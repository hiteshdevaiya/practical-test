<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\ApiClient;
use App\Services\Usage\SearchCounter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports the client's billable usage and free search request count.
 */
final class UsageController extends ApiController
{
    /**
     * @param SearchCounter $counter Free search counter.
     */
    public function __construct(
        private readonly SearchCounter $counter,
    ) {}

    /**
     * Return the current usage totals.
     *
     * @param Request $request Current request.
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $client = $this->client($request);

        // Read the counter fresh so selection responses are immediately visible.
        $fresh = ApiClient::query()->find($client->id);

        return response()->json([
            'billable_usage_count' => (int) ($fresh?->billable_usage_count ?? 0),
            'search_requests' => $this->counter->get($client->id),
        ]);
    }
}
