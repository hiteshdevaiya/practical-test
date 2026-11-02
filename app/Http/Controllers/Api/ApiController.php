<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\ApiClient;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base controller for API endpoints that require an authenticated client.
 */
abstract class ApiController
{
    /**
     * Resolve the API client attached by the authentication middleware.
     *
     * @param Request $request Current request.
     * @return ApiClient
     *
     * @throws RuntimeException When the middleware did not run.
     */
    protected function client(Request $request): ApiClient
    {
        $client = $request->attributes->get('api_client');

        if (! $client instanceof ApiClient) {
            throw new RuntimeException('API client was not resolved by middleware.');
        }

        return $client;
    }
}
