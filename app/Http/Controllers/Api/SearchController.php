<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\SearchRequest;
use App\Services\Search\CachedAutocompleteSearch;
use App\Services\Search\SearchMetrics;
use App\Services\Usage\SearchCounter;
use App\Services\Usage\SelectionTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Handles non-billable autocomplete searches.
 *
 * This endpoint performs no database writes. Each suggestion receives its
 * own signed selection token, which is the only way to trigger billing.
 */
final class SearchController extends ApiController
{
    /**
     * @param CachedAutocompleteSearch $search Cached search service.
     * @param SelectionTokenService $tokens Selection token issuer.
     * @param SearchCounter $counter Free search counter.
     * @param SearchMetrics $metrics Per-request timing collector.
     */
    public function __construct(
        private readonly CachedAutocompleteSearch $search,
        private readonly SelectionTokenService $tokens,
        private readonly SearchCounter $counter,
        private readonly SearchMetrics $metrics,
    ) {}

    /**
     * Return ranked suggestions for the given query.
     *
     * @param SearchRequest $request Validated request.
     * @return JsonResponse
     */
    public function __invoke(SearchRequest $request): JsonResponse
    {
        $client = $this->client($request);
        $limit = (int) ($request->validated('limit') ?? config('search.default_limit', 10));

        $result = $this->search->search((string) $request->validated('q'), $limit);
        $searchId = (string) Str::uuid();
        $data = [];

        foreach ($result->suggestions as $suggestion) {
            $data[] = [
                ...$suggestion->toArray(),
                'selection_token' => $this->tokens->issue(
                    $client->id,
                    $suggestion->id,
                    $searchId,
                    $result->query,
                ),
            ];
        }

        $this->counter->increment($client->id);

        $this->metrics->record(
            $result->dbMs,
            $result->cacheHit ? 'hit' : (config('search.cache.enabled') ? 'miss' : 'off'),
        );

        return response()->json([
            'query' => $result->query,
            'corrected_query' => $result->correctedQuery,
            'took_ms' => round($result->tookMs, 2),
            'data' => $data,
        ]);
    }
}
