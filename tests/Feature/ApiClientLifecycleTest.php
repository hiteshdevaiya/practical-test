<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Verifies API client revocation and usage counter reconciliation.
 */
final class ApiClientLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Revoking a client immediately invalidates its cached credentials.
     *
     * @return void
     */
    public function test_revocation_invalidates_cached_credentials(): void
    {
        [$client, $key] = $this->createApiClient('Revocable Client');

        // First call caches the resolved client in the API key cache.
        $this->getJson('/api/v1/usage', $this->apiHeaders($key))->assertOk();

        $exit = Artisan::call('api-client:revoke', ['client' => (string) $client->id]);
        $this->assertSame(0, $exit);
        $this->assertFalse($client->fresh()->is_active);

        $this->getJson('/api/v1/usage', $this->apiHeaders($key))->assertStatus(401);
    }

    /**
     * Reactivation restores access without changing the usage counter.
     *
     * @return void
     */
    public function test_reactivation_restores_access(): void
    {
        [$client, $key] = $this->createApiClient('Reactivated Client');

        Artisan::call('api-client:revoke', ['client' => (string) $client->id]);
        $this->getJson('/api/v1/usage', $this->apiHeaders($key))->assertStatus(401);

        $exit = Artisan::call('api-client:revoke', [
            'client' => (string) $client->id,
            '--reactivate' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->getJson('/api/v1/usage', $this->apiHeaders($key))->assertOk();
    }

    /**
     * The reconciler detects a counter that does not match the ledger and can
     * repair it from the append-only event table.
     *
     * @return void
     */
    public function test_reconcile_detects_and_repairs_counter_drift(): void
    {
        [$client] = $this->createApiClient('Drifted Client');

        ApiClient::query()->whereKey($client->id)->update(['billable_usage_count' => 7]);

        $this->assertSame(1, Artisan::call('usage:reconcile'));
        $this->assertSame(7, (int) $client->fresh()->billable_usage_count);

        $this->assertSame(0, Artisan::call('usage:reconcile', ['--fix' => true]));
        $this->assertSame(0, (int) $client->fresh()->billable_usage_count);
    }
}
