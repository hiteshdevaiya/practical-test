<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\ApiUsageEvent;
use App\Services\Usage\SelectionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies selection-based billing, idempotency and token security.
 */
final class SelectionUsageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authenticated client used by most tests.
     */
    private ApiClient $client;

    /**
     * Plain API key used by the tests.
     */
    private string $key;

    /**
     * Seed a deterministic fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        [$this->client, $this->key] = $this->createApiClient();
        $this->seedRecords([
            '25 London Road Leeds',
            '91 London Road Manchester',
            '118 Church Lane Bristol',
        ]);
        $this->rebuildVocabulary();
    }

    /**
     * Obtain the first selection token for a query.
     *
     * @param string $key API key.
     * @param string $query Search query.
     * @param string $client Optional different client key.
     * @return string Signed token.
     */
    private function tokenFor(string $key, string $query = 'london', ?string $client = null): string
    {
        $data = $this->getJson(
            '/api/v1/search?q='.urlencode($query),
            $this->apiHeaders($client ?? $key)
        )->assertOk()->json('data');

        $this->assertNotEmpty($data, 'Expected at least one suggestion.');

        return $data[0]['selection_token'];
    }

    /**
     * One selection counts exactly one usage.
     *
     * @return void
     */
    public function test_selecting_a_suggestion_counts_once(): void
    {
        $token = $this->tokenFor($this->key);

        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))
            ->assertOk()
            ->assertJsonPath('usage.counted', true)
            ->assertJsonPath('usage.billable_usage_count', 1);

        $this->assertSame(1, ApiUsageEvent::query()->count());
    }

    /**
     * Two different tokens count two usages.
     *
     * @return void
     */
    public function test_selecting_two_suggestions_counts_two(): void
    {
        $first = $this->tokenFor($this->key, 'london');
        $second = $this->tokenFor($this->key, 'church');

        $this->postJson('/api/v1/search/select', ['selection_token' => $first], $this->apiHeaders($this->key))
            ->assertJsonPath('usage.billable_usage_count', 1);

        $this->postJson('/api/v1/search/select', ['selection_token' => $second], $this->apiHeaders($this->key))
            ->assertJsonPath('usage.billable_usage_count', 2);

        $this->assertSame(2, ApiUsageEvent::query()->count());
    }

    /**
     * Replaying a token is idempotent and does not bill twice.
     *
     * @return void
     */
    public function test_replayed_token_is_not_counted_again(): void
    {
        $token = $this->tokenFor($this->key);

        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))
            ->assertJsonPath('usage.counted', true);

        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))
            ->assertOk()
            ->assertJsonPath('usage.counted', false)
            ->assertJsonPath('usage.billable_usage_count', 1);

        $this->assertSame(1, ApiUsageEvent::query()->count());
    }

    /**
     * Tampered tokens are rejected without changing the counter.
     *
     * @return void
     */
    public function test_tampered_token_is_rejected(): void
    {
        $token = $this->tokenFor($this->key);

        $this->postJson(
            '/api/v1/search/select',
            ['selection_token' => $token.'tampered'],
            $this->apiHeaders($this->key)
        )->assertStatus(422);

        $this->assertSame(0, ApiUsageEvent::query()->count());
    }

    /**
     * Expired tokens are rejected without changing the counter.
     *
     * @return void
     */
    public function test_expired_token_is_rejected(): void
    {
        $token = $this->tokenFor($this->key);

        $this->travel(11)->minutes();

        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))
            ->assertStatus(422);

        $this->assertSame(0, ApiUsageEvent::query()->count());
    }

    /**
     * A token issued to one client cannot be billed by another client.
     *
     * @return void
     */
    public function test_other_clients_token_is_forbidden(): void
    {
        [, $otherKey] = $this->createApiClient('Other Client');
        $token = $this->tokenFor($this->key);

        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($otherKey))
            ->assertStatus(403);

        $this->assertSame(0, ApiUsageEvent::query()->count());
    }

    /**
     * A valid token for a deleted record returns 404 and does not bill.
     *
     * @return void
     */
    public function test_missing_record_is_not_billed(): void
    {
        $service = new SelectionTokenService(
            (string) config('search.selection_token.secret'),
            (int) config('search.selection_token.ttl')
        );

        $token = $service->issue($this->client->id, 999999, (string) Str::uuid(), 'ghost');

        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))
            ->assertStatus(404);

        $this->assertSame(0, ApiUsageEvent::query()->count());
    }

    /**
     * Missing tokens fail validation.
     *
     * @return void
     */
    public function test_missing_token_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/search/select', [], $this->apiHeaders($this->key))
            ->assertStatus(422);
    }

    /**
     * The usage endpoint reflects selections and remains consistent with the ledger.
     *
     * @return void
     */
    public function test_usage_endpoint_matches_the_ledger(): void
    {
        $this->postJson(
            '/api/v1/search/select',
            ['selection_token' => $this->tokenFor($this->key)],
            $this->apiHeaders($this->key)
        )->assertOk();

        $this->getJson('/api/v1/usage', $this->apiHeaders($this->key))
            ->assertOk()
            ->assertJsonPath('billable_usage_count', 1);

        $ledger = DB::table('api_usage_events')->count();
        $this->assertSame(1, $ledger);
    }

    /**
     * Selection is also rate limited.
     *
     * @return void
     */
    public function test_select_endpoint_is_rate_limited(): void
    {
        config(['search.rate_limits.select' => 2]);
        $token = $this->tokenFor($this->key);

        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))->assertOk();
        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))->assertOk();
        $this->postJson('/api/v1/search/select', ['selection_token' => $token], $this->apiHeaders($this->key))->assertStatus(429);
    }
}
