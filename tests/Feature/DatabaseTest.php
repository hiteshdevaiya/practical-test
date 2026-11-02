<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiUsageEvent;
use App\Services\Usage\SelectionClaims;
use App\Services\Usage\UsageRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies PostgreSQL structure, constraints and read-only permissions.
 */
final class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The application connects to PostgreSQL with pg_trgm available.
     *
     * @return void
     */
    public function test_postgresql_connection_and_extension(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $extension = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'");
        $this->assertNotNull($extension);
    }

    /**
     * The GIN trigram index exists on records.content.
     *
     * @return void
     */
    public function test_trigram_index_exists(): void
    {
        $index = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'records' AND indexname = 'records_content_trgm_idx'"
        );

        $this->assertNotNull($index);
        $this->assertStringContainsString('gin', strtolower($index->indexdef));
        $this->assertStringContainsString('gin_trgm_ops', $index->indexdef);
    }

    /**
     * Expected tables and foreign keys exist.
     *
     * @return void
     */
    public function test_expected_tables_and_constraints_exist(): void
    {
        foreach (['records', 'search_vocabulary', 'api_clients', 'api_usage_events'] as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), "Missing table {$table}");
        }

        $foreignKeys = DB::select(<<<'SQL'
            SELECT conname FROM pg_constraint
            WHERE contype = 'f' AND conrelid = 'api_usage_events'::regclass
        SQL);

        $this->assertGreaterThanOrEqual(2, count($foreignKeys));
    }

    /**
     * selection_id is unique at the database level.
     *
     * @return void
     */
    public function test_duplicate_selection_id_is_rejected_by_the_database(): void
    {
        [$client] = $this->createApiClient();
        $this->seedRecords(['25 London Road Leeds']);
        $recordId = (int) DB::table('records')->value('id');
        $selectionId = (string) Str::uuid();

        $row = [
            'api_client_id' => $client->id,
            'record_id' => $recordId,
            'selection_id' => $selectionId,
            'search_id' => (string) Str::uuid(),
            'query' => 'london',
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => now(),
        ];

        DB::table('api_usage_events')->insert($row);

        $failed = false;

        try {
            DB::transaction(static fn () => DB::table('api_usage_events')->insert($row));
        } catch (QueryException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'The unique selection_id constraint did not reject a duplicate.');
        $this->assertSame(1, ApiUsageEvent::query()->count());
    }

    /**
     * The usage counter can only advance for a new selection id.
     *
     * @return void
     */
    public function test_usage_recorder_is_idempotent(): void
    {
        [$client] = $this->createApiClient();
        $this->seedRecords(['25 London Road Leeds']);
        $recordId = (int) DB::table('records')->value('id');

        $claims = new SelectionClaims(
            $client->id,
            $recordId,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'london',
            now()->addMinutes(10)->timestamp,
        );

        $recorder = app(UsageRecorder::class);
        $request = request();

        $first = $recorder->record($client, $claims, $request);
        $second = $recorder->record($client, $claims, $request);

        $this->assertTrue($first->counted);
        $this->assertSame(1, $first->billableUsageCount);
        $this->assertFalse($second->counted);
        $this->assertSame(1, $second->billableUsageCount);
        $this->assertSame(1, ApiUsageEvent::query()->count());
    }

    /**
     * Counters cannot be driven negative by direct updates.
     *
     * @return void
     */
    public function test_usage_counter_has_a_non_negative_constraint(): void
    {
        [$client] = $this->createApiClient();
        $failed = false;

        try {
            DB::transaction(static fn () => DB::table('api_clients')
                ->where('id', $client->id)
                ->update(['billable_usage_count' => -1]));
        } catch (QueryException) {
            $failed = true;
        }

        $this->assertTrue($failed);
    }

    /**
     * The dedicated search role cannot write to the database.
     *
     * @return void
     */
    public function test_search_connection_is_read_only(): void
    {
        $config = config('database.connections.pgsql_search');
        $config['driver'] = 'pgsql';

        DB::connectUsing('readonly_probe', $config);
        $connection = DB::connection('readonly_probe');

        $readOnly = (string) $connection->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only;
        $this->assertSame('on', $readOnly);

        $failed = false;

        try {
            $connection->beginTransaction();
            $connection->table('records')->insert(['content' => 'write probe']);
        } catch (QueryException) {
            $failed = true;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            DB::purge('readonly_probe');
        }

        $this->assertTrue($failed, 'The read-only search role was able to write.');
    }
}
