<?php

declare(strict_types=1);

namespace Tests;

use App\Models\ApiClient;
use App\Services\Search\PrefixIndexBuilder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Base test case for the application.
 *
 * The search connection is aliased to the default connection so queries made
 * by the read-only search path can see rows created inside the test
 * transaction. The dedicated read-only role is exercised separately with its
 * own connection.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Configure the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Blade pages reference Vite assets; tests must not depend on a build.
        $this->withoutVite();

        DB::extend('pgsql_search', static fn () => DB::connection('pgsql'));
        DB::purge('pgsql_search');
    }

    /**
     * Create an API client and return its plain key.
     *
     * @param string $name Client name.
     * @param bool $active Whether the client is active.
     * @return array{0:ApiClient,1:string} Client and plaintext key.
     */
    protected function createApiClient(string $name = 'Test Client', bool $active = true): array
    {
        $key = 'test_live_'.Str::random(32);

        $client = ApiClient::query()->create([
            'name' => $name,
            'api_key_hash' => hash('sha256', $key),
            'billable_usage_count' => 0,
            'is_active' => $active,
        ]);

        return [$client, $key];
    }

    /**
     * Insert records directly for search tests.
     *
     * @param list<string> $contents Record contents.
     * @return void
     */
    protected function seedRecords(array $contents): void
    {
        $rows = array_map(static fn (string $content): array => ['content' => $content], $contents);

        DB::connection('pgsql')->table('records')->insert($rows);

        // The candidate path reads the derived B-tree prefix index, so it has
        // to reflect the fixture rows before a test searches.
        app(PrefixIndexBuilder::class)->rebuild();
    }

    /**
     * Rebuild the typo-correction vocabulary from current records.
     *
     * @return void
     */
    protected function rebuildVocabulary(): void
    {
        DB::connection('pgsql')->statement('TRUNCATE TABLE search_vocabulary');
        DB::connection('pgsql')->statement(<<<'SQL'
            INSERT INTO search_vocabulary (word, row_count)
            SELECT word, count(*)
            FROM records, regexp_split_to_table(lower(content), '\s+') AS word
            WHERE length(word) >= 3 AND word !~ '^\d+$'
            GROUP BY word
        SQL);
    }

    /**
     * Standard JSON headers for an API key.
     *
     * @param string $key Plain API key.
     * @return array<string, string>
     */
    protected function apiHeaders(string $key): array
    {
        return [
            'X-API-Key' => $key,
            'Accept' => 'application/json',
        ];
    }
}
