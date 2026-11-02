<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies API key authentication on every endpoint.
 */
final class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every protected endpoint requires an API key.
     *
     * @return void
     */
    public function test_endpoints_reject_missing_api_keys(): void
    {
        $this->getJson('/api/v1/search?q=london')->assertStatus(401);
        $this->postJson('/api/v1/search/select', ['selection_token' => 'x'])->assertStatus(401);
        $this->getJson('/api/v1/usage')->assertStatus(401);
    }

    /**
     * Unknown keys are rejected.
     *
     * @return void
     */
    public function test_unknown_api_keys_are_rejected(): void
    {
        $this->getJson('/api/v1/search?q=london', $this->apiHeaders('nope'))
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid API key.']);
    }

    /**
     * Deactivated clients are rejected even with a previously valid key.
     *
     * @return void
     */
    public function test_inactive_clients_are_rejected(): void
    {
        [, $key] = $this->createApiClient('Inactive', false);

        $this->getJson('/api/v1/search?q=london', $this->apiHeaders($key))
            ->assertStatus(401);
    }

    /**
     * A valid active key can access protected endpoints.
     *
     * @return void
     */
    public function test_valid_api_keys_are_accepted(): void
    {
        [, $key] = $this->createApiClient();

        $this->getJson('/api/v1/usage', $this->apiHeaders($key))
            ->assertOk()
            ->assertJson([
                'billable_usage_count' => 0,
                'search_requests' => 0,
            ]);
    }
}
