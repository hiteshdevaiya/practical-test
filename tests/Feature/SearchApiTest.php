<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiUsageEvent;
use App\Services\Search\AutocompleteSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies autocomplete behavior, validation and the free-search guarantee.
 */
final class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Plain API key used by the tests.
     */
    private string $key;

    /**
     * Seed a small, deterministic fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        [, $this->key] = $this->createApiClient();

        $this->seedRecords([
            '25 London Road Leeds',
            '118 Church Lane Bristol Clifton',
            '7 London Street York',
            '45 Station Road Bath',
            '91 London Road Manchester Didsbury',
        ]);

        $this->rebuildVocabulary();
    }

    /**
     * Partial fragments must all appear in the returned content.
     *
     * @return void
     */
    public function test_search_returns_partial_matches(): void
    {
        $response = $this->getJson('/api/v1/search?q=lond%20roa&limit=10', $this->apiHeaders($this->key));

        $response->assertOk()->assertJsonPath('query', 'lond roa');

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        foreach ($data as $row) {
            $this->assertStringContainsStringIgnoringCase('lond', $row['content']);
            $this->assertStringContainsStringIgnoringCase('roa', $row['content']);
        }
    }

    /**
     * Tokens may be entered in any order.
     *
     * @return void
     */
    public function test_search_matches_tokens_in_any_order(): void
    {
        $forward = $this->getJson('/api/v1/search?q=lond%20roa', $this->apiHeaders($this->key))->json('data');
        $reverse = $this->getJson('/api/v1/search?q=roa%20lond', $this->apiHeaders($this->key))->json('data');

        $this->assertSame(
            array_column($forward, 'id'),
            array_column($reverse, 'id')
        );
    }

    /**
     * Unknown tokens are corrected against the vocabulary.
     *
     * @return void
     */
    public function test_search_corrects_typos(): void
    {
        $response = $this->getJson('/api/v1/search?q=londn%20road', $this->apiHeaders($this->key));

        $response->assertOk()->assertJsonPath('corrected_query', 'london road');

        foreach ($response->json('data') as $row) {
            $this->assertStringContainsStringIgnoringCase('london', $row['content']);
            $this->assertSame('corrected', $row['match']);
        }
    }

    /**
     * Queries shorter than the minimum never reach the database.
     *
     * @return void
     */
    public function test_short_queries_skip_the_database(): void
    {
        DB::connection('pgsql')->flushQueryLog();
        DB::connection('pgsql')->enableQueryLog();

        $result = app(AutocompleteSearch::class)->search('lo', 10);

        $this->assertSame([], $result->suggestions);
        $this->assertCount(0, DB::connection('pgsql')->getQueryLog());
    }

    /**
     * Short queries return an empty, successful response.
     *
     * @return void
     */
    public function test_short_query_returns_empty_data(): void
    {
        $this->getJson('/api/v1/search?q=lo', $this->apiHeaders($this->key))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Invalid input is rejected by validation.
     *
     * @return void
     */
    public function test_search_validates_input(): void
    {
        $this->getJson('/api/v1/search', $this->apiHeaders($this->key))->assertStatus(422);
        $this->getJson('/api/v1/search?q=london&limit=21', $this->apiHeaders($this->key))->assertStatus(422);
        $this->getJson('/api/v1/search?q=london&limit=0', $this->apiHeaders($this->key))->assertStatus(422);
    }

    /**
     * Results are capped by the requested limit.
     *
     * @return void
     */
    public function test_search_respects_the_limit(): void
    {
        $this->getJson('/api/v1/search?q=london&limit=2', $this->apiHeaders($this->key))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Every suggestion carries a signed selection token.
     *
     * @return void
     */
    public function test_suggestions_carry_selection_tokens(): void
    {
        $data = $this->getJson('/api/v1/search?q=london', $this->apiHeaders($this->key))->json('data');

        $this->assertNotEmpty($data);

        foreach ($data as $row) {
            $this->assertArrayHasKey('selection_token', $row);
            $this->assertIsString($row['selection_token']);
            $this->assertStringContainsString('.', $row['selection_token']);
        }
    }

    /**
     * The Server-Timing header exposes total, database and cache timings.
     *
     * @return void
     */
    public function test_server_timing_reports_database_and_cache_segments(): void
    {
        $response = $this->getJson('/api/v1/search?q=lond', $this->apiHeaders($this->key));

        $response->assertOk();
        $header = (string) $response->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/app;dur=\d+(\.\d+)?/', $header);
        $this->assertMatchesRegularExpression('/db;dur=\d+(\.\d+)?/', $header);
        $this->assertMatchesRegularExpression('/cache;desc="(hit|miss|off)"/', $header);
    }

    /**
     * Short queries report zero database time because they never query.
     *
     * @return void
     */
    public function test_server_timing_reports_zero_database_time_for_short_queries(): void
    {
        $response = $this->getJson('/api/v1/search?q=lo', $this->apiHeaders($this->key));

        $response->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringContainsString('db;dur=0.00', (string) $response->headers->get('Server-Timing'));
    }

    /**
     * One-character tokens are matched on a word boundary.
     *
     * A single character is too short for a trigram, so it can only act as a
     * filter. It must still be exact: "5" must not match "15".
     *
     * @return void
     */
    public function test_one_character_tokens_match_whole_words(): void
    {
        $this->seedRecords([
            '5 Alpha Road Leeds',
            '15 Alpha Road Leeds',
            '25 Alpha Road Leeds',
        ]);

        $data = $this->getJson('/api/v1/search?q=alpha%205&limit=10', $this->apiHeaders($this->key))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data);
        $this->assertSame(['5 Alpha Road Leeds'], array_column($data, 'content'));
    }

    /**
     * Any number of searches leaves the billable counter and ledger untouched.
     *
     * @return void
     */
    public function test_search_never_changes_billable_usage(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->getJson('/api/v1/search?q=london', $this->apiHeaders($this->key))->assertOk();
        }

        $this->assertSame(0, ApiUsageEvent::query()->count());
        $this->getJson('/api/v1/usage', $this->apiHeaders($this->key))
            ->assertOk()
            ->assertJsonPath('billable_usage_count', 0)
            ->assertJsonPath('search_requests', 50);
    }

    /**
     * The search endpoint is rate limited.
     *
     * @return void
     */
    public function test_search_endpoint_is_rate_limited(): void
    {
        config(['search.rate_limits.search' => 2]);

        $this->getJson('/api/v1/search?q=london', $this->apiHeaders($this->key))->assertOk();
        $this->getJson('/api/v1/search?q=london', $this->apiHeaders($this->key))->assertOk();
        $this->getJson('/api/v1/search?q=london', $this->apiHeaders($this->key))->assertStatus(429);
    }

    /**
     * A result set is never larger than the service limit.
     *
     * @return void
     */
    public function test_search_limits_are_clamped(): void
    {
        $result = app(AutocompleteSearch::class)->search('london', 999);

        $this->assertLessThanOrEqual((int) config('search.max_limit'), count($result->suggestions));
    }
}
