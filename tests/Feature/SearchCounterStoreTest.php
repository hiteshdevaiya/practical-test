<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Usage\SearchCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Verifies the free search counter against persistent cache stores.
 *
 * The in-memory array store can increment a missing key, but the database and
 * file stores cannot. The demo runs on the database store locally, so this is
 * the configuration that has to be covered.
 */
final class SearchCounterStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A missing key must be created before it can be incremented.
     *
     * @return void
     */
    public function test_database_store_increments_from_zero(): void
    {
        $store = Cache::store('database');
        $store->flush();

        $counter = new SearchCounter($store);
        $counter->increment(42);
        $counter->increment(42);

        $this->assertSame(2, $counter->get(42));
        $this->assertSame(0, $counter->get(43));
    }

    /**
     * The counter is observable through the API, proving searches are free.
     *
     * @return void
     */
    public function test_usage_endpoint_reports_the_free_search_count(): void
    {
        [$client, $key] = $this->createApiClient();
        $this->seedRecords(['25 London Road Leeds']);

        $this->getJson('/api/v1/search?q=london', $this->apiHeaders($key))->assertOk();
        $this->getJson('/api/v1/search?q=lon', $this->apiHeaders($key))->assertOk();

        $response = $this->getJson('/api/v1/usage', $this->apiHeaders($key))->assertOk();

        $this->assertSame(2, $response->json('search_requests'));
        $this->assertSame(0, $response->json('billable_usage_count'));
        $this->assertSame(0, (int) $client->fresh()->billable_usage_count);
    }
}
