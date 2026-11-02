<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\AutocompleteSearch;
use App\Services\Search\QueryNormalizer;
use App\Services\Search\TypoCorrector;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDOException;
use Tests\TestCase;

/**
 * Verifies that database failures inside the staged search degrade instead of
 * surfacing as errors.
 *
 * The search tries the B-tree word tables, then the trigram index. A statement
 * timeout or a missing derived table in the first stage must fall through to
 * the next, and a timeout in the last stage must return what was found instead
 * of failing the request.
 */
final class SearchFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed fixture rows and the typo vocabulary.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRecords([
            '25 Greenwich Road Leeds',
            '91 Greenwich Road Manchester',
            '118 Church Lane Bristol',
        ]);
        $this->rebuildVocabulary();
    }

    /**
     * Build the search service around a connection that can inject failures.
     *
     * @param Closure(string, array<int, mixed>): void $intercept Called with
     *                                                            each SQL statement and its bindings before it runs; throw to
     *                                                            simulate a database failure.
     * @return AutocompleteSearch
     */
    private function service(Closure $intercept): AutocompleteSearch
    {
        $real = DB::connection('pgsql');
        $connection = Mockery::mock($real);
        $connection->shouldReceive('select')->andReturnUsing(
            function (string $sql, array $bindings = [], bool $useReadPdo = true) use ($real, $intercept): array {
                $intercept($sql, $bindings);

                return $real->select($sql, $bindings, $useReadPdo);
            }
        );

        return new AutocompleteSearch(
            $connection,
            app(QueryNormalizer::class),
            app(TypoCorrector::class),
            (int) config('search.candidate_cap'),
            (int) config('search.max_limit'),
            (int) config('search.min_token_length'),
            (int) config('search.filter_candidate_cap'),
        );
    }

    /**
     * Build a PostgreSQL error the way PDO reports it.
     *
     * @param string $sqlState SQLSTATE code.
     * @param string $message Driver message.
     * @param string $sql Failing statement.
     * @return QueryException
     */
    private function databaseError(string $sqlState, string $message, string $sql): QueryException
    {
        $pdo = new PDOException($message);
        $pdo->errorInfo = [$sqlState, 7, $message];

        return new QueryException('pgsql', $sql, [], $pdo);
    }

    /**
     * Whether a statement is the trigram candidate query with two index tokens.
     *
     * @param string $sql Statement text.
     * @return bool
     */
    private function isTwoTokenTrigramQuery(string $sql): bool
    {
        return str_contains($sql, 'WITH candidates AS')
            && str_contains($sql, 'content ILIKE ? AND content ILIKE ?');
    }

    /**
     * A timeout on the multi-token trigram plan degrades to a superset.
     *
     * Both tokens are mid-word fragments, so only the trigram plan can match.
     * Once it times out, the longest token alone must still return rows.
     *
     * @return void
     */
    public function test_trigram_timeout_degrades_to_a_superset_for_multiple_tokens(): void
    {
        Log::spy();

        $service = $this->service(function (string $sql): void {
            if ($this->isTwoTokenTrigramQuery($sql)) {
                throw $this->databaseError('57014', 'canceling statement due to statement timeout', $sql);
            }
        });

        $result = $service->search('enwic oad', 10);

        $contents = array_map(static fn ($suggestion): string => $suggestion->content, $result->suggestions);

        $this->assertContains('25 Greenwich Road Leeds', $contents);
        $this->assertContains('91 Greenwich Road Manchester', $contents);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    /**
     * A timeout on a single-token trigram plan returns no rows, not an error.
     *
     * @return void
     */
    public function test_trigram_timeout_on_a_single_token_returns_empty_instead_of_failing(): void
    {
        $service = $this->service(function (string $sql): void {
            if (str_contains($sql, 'WITH candidates AS') && str_contains($sql, 'content ILIKE ?')) {
                throw $this->databaseError('57014', 'canceling statement due to statement timeout', $sql);
            }
        });

        $result = $service->search('enwic', 10);

        $this->assertSame([], $result->suggestions);
    }

    /**
     * A timeout in the prefix-index stage falls through to later stages.
     *
     * @return void
     */
    public function test_prefix_index_timeout_falls_back_to_other_plans(): void
    {
        $service = $this->service(function (string $sql): void {
            if (str_contains($sql, 'candidate_ids')) {
                throw $this->databaseError('57014', 'canceling statement due to statement timeout', $sql);
            }
        });

        $result = $service->search('greenwich road', 10);

        $this->assertCount(2, $result->suggestions);
    }

    /**
     * Search keeps working when the derived word tables are missing.
     *
     * @return void
     */
    public function test_missing_prefix_tables_fall_back_to_other_plans(): void
    {
        $service = $this->service(function (string $sql): void {
            if (str_contains($sql, 'record_word')) {
                throw $this->databaseError('42P01', 'relation "record_words" does not exist', $sql);
            }
        });

        $result = $service->search('greenwich road', 10);

        $this->assertCount(2, $result->suggestions);
    }

    /**
     * With fresh derived indexes a typo never pays for the exact trigram scan.
     *
     * No vocabulary word contains the typo, so that scan cannot match; at ten
     * million rows it is the most expensive statement in the pipeline.
     *
     * @return void
     */
    public function test_typo_skips_the_exact_trigram_scan_when_indexes_are_fresh(): void
    {
        $typoScans = 0;

        $service = $this->service(function (string $sql, array $bindings) use (&$typoScans): void {
            if (str_contains($sql, 'WITH candidates AS') && in_array('%greenwhch%', $bindings, true)) {
                $typoScans++;
            }
        });

        $result = $service->search('greenwhch road', 10);

        $this->assertSame(0, $typoScans);
        $this->assertSame('greenwich road', $result->correctedQuery);
        $this->assertCount(2, $result->suggestions);
    }

    /**
     * Records added after the index build are still found by an exact query.
     *
     * @return void
     */
    public function test_rows_added_after_the_index_build_are_found_by_the_exact_scan(): void
    {
        DB::table('records')->insert(['content' => '7 Greenwhch Road Bath']);

        $result = $this->service(static function (): void {})->search('greenwhch road', 10);

        $contents = array_map(static fn ($suggestion): string => $suggestion->content, $result->suggestions);

        $this->assertContains('7 Greenwhch Road Bath', $contents);
    }

    /**
     * Hostile input is only ever data: it cannot change a statement, and it
     * cannot act as a LIKE wildcard.
     *
     * @return void
     */
    public function test_hostile_input_is_treated_as_data(): void
    {
        $service = $this->service(static function (): void {});

        foreach ([
            "greenwich'; DROP TABLE records; --",
            'greenwich" OR 1=1 --',
            '%%% ___ \\',
            "church lane bri' UNION SELECT content FROM records --",
            "\u{202E}greenwich\u{0000}road",
        ] as $input) {
            $result = $service->search($input, 10);

            $this->assertIsArray($result->suggestions);
        }

        $this->assertSame(3, DB::table('records')->count());
        $this->assertSame([], $service->search('%%%', 10)->suggestions);
        $this->assertSame([], $service->search('___', 10)->suggestions);
    }

    /**
     * Errors that are not timeouts are not swallowed.
     *
     * @return void
     */
    public function test_other_database_errors_still_surface(): void
    {
        $service = $this->service(function (string $sql): void {
            if (str_contains($sql, 'candidate_ids')) {
                throw $this->databaseError('22P02', 'invalid input syntax', $sql);
            }
        });

        $this->expectException(QueryException::class);

        $service->search('greenwich road', 10);
    }
}
