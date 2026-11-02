<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies migration execution, schema shape, indexes and rollback support.
 */
final class MigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every application migration ran and ships a down() implementation.
     *
     * @return void
     */
    public function test_all_migrations_ran_and_are_reversible(): void
    {
        Artisan::call('migrate:status');
        $status = Artisan::output();

        $files = glob(database_path('migrations/*.php')) ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $this->assertStringContainsString($name, $status, "Migration {$name} is not recorded.");
            $this->assertStringNotContainsString(
                'Pending',
                $this->statusLine($status, $name),
                "Migration {$name} is still pending."
            );

            $migration = require $file;

            $this->assertInstanceOf(Migration::class, $migration, "{$name} must return a migration.");
            $this->assertTrue(method_exists($migration, 'down'), "{$name} has no down() method.");
        }
    }

    /**
     * The records table is intentionally minimal and has no timestamps.
     *
     * @return void
     */
    public function test_records_table_shape(): void
    {
        $columns = DB::select(<<<'SQL'
            SELECT column_name, data_type, is_nullable
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'records'
            ORDER BY ordinal_position
        SQL);

        $this->assertSame(['id', 'content'], array_column($columns, 'column_name'));
        $this->assertSame('NO', $columns[1]->is_nullable);
        $this->assertSame('text', $columns[1]->data_type);
    }

    /**
     * selection_id is protected by a unique index at the database level.
     *
     * @return void
     */
    public function test_selection_id_unique_constraint_exists(): void
    {
        $unique = DB::selectOne(<<<'SQL'
            SELECT i.indisunique
            FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = 'api_usage_events'::regclass
              AND a.attname = 'selection_id'
              AND i.indisunique
        SQL);

        $this->assertNotNull($unique, 'selection_id has no unique index.');
    }

    /**
     * The non-negative usage counter check constraint exists.
     *
     * @return void
     */
    public function test_usage_counter_check_constraint_exists(): void
    {
        $constraint = DB::selectOne(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'api_clients'::regclass
              AND contype = 'c'
              AND conname = 'api_clients_usage_count_non_negative'
        SQL);

        $this->assertNotNull($constraint);
    }

    /**
     * The trigram index is a GIN index using gin_trgm_ops.
     *
     * @return void
     */
    public function test_trigram_index_uses_gin_trgm_ops(): void
    {
        $definition = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'records' AND indexname = 'records_content_trgm_idx'"
        );

        $this->assertNotNull($definition);
        $this->assertStringContainsString('USING gin', $definition->indexdef);
        $this->assertStringContainsString('gin_trgm_ops', $definition->indexdef);
    }

    /**
     * Short, word-aligned fragments are served by a padded expression index.
     *
     * @return void
     */
    public function test_padded_trigram_index_exists(): void
    {
        $definition = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'records' AND indexname = 'records_content_padded_trgm_idx'"
        );

        $this->assertNotNull($definition);
        $this->assertStringContainsString('USING gin', $definition->indexdef);
        $this->assertStringContainsString('gin_trgm_ops', $definition->indexdef);
        $this->assertStringContainsString('lower(content)', $definition->indexdef);
    }

    /**
     * Extract the status line for one migration from migrate:status output.
     *
     * @param string $status Output of migrate:status.
     * @param string $name Migration name.
     * @return string
     */
    private function statusLine(string $status, string $name): string
    {
        foreach (preg_split('/\R/', $status) ?: [] as $line) {
            if (str_contains($line, $name)) {
                return $line;
            }
        }

        return '';
    }
}
