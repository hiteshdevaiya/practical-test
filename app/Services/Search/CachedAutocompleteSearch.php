<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Caches search result rows while keeping selection tokens per response.
 *
 * Only record rows and the corrected query are stored. Tokens are signed by
 * the controller after this decorator returns, so a cached entry can never
 * reuse or leak a billable token.
 */
final class CachedAutocompleteSearch implements SearchService
{
    /**
     * @param SearchService $inner Underlying search implementation.
     * @param CacheRepository $cache Cache store (Redis or Laravel default).
     * @param bool $enabled Whether caching is enabled.
     * @param int $ttl Cache lifetime in seconds.
     * @param string $prefix Cache key namespace/version.
     * @param QueryNormalizer $normalizer Normalizer used for stable cache keys.
     */
    public function __construct(
        private readonly SearchService $inner,
        private readonly CacheRepository $cache,
        private readonly bool $enabled,
        private readonly int $ttl,
        private readonly string $prefix,
        private readonly QueryNormalizer $normalizer,
    ) {}

    /**
     * Search through the cache layer.
     *
     * @param string $raw Untrusted user input.
     * @param int $limit Desired number of suggestions.
     * @return SearchResult Suggestions with timing metadata.
     */
    public function search(string $raw, int $limit): SearchResult
    {
        if (! $this->enabled) {
            return $this->inner->search($raw, $limit);
        }

        $normalized = $this->normalizer->normalize($raw);
        $key = $this->prefix.':'.sha1($normalized.'|'.$limit);
        $startedAt = microtime(true);

        try {
            $cached = $this->cache->get($key);
        } catch (Throwable $exception) {
            report($exception);
            $cached = null;
        }

        if (is_array($cached) && isset($cached['rows']) && is_array($cached['rows'])) {
            $suggestions = array_map(
                static fn (array $row): Suggestion => new Suggestion(
                    (int) $row['id'],
                    (string) $row['content'],
                    (string) $row['match'],
                ),
                $cached['rows']
            );

            $result = new SearchResult(
                (string) ($cached['query'] ?? $normalized),
                isset($cached['corrected_query']) ? (string) $cached['corrected_query'] : null,
                $suggestions,
                0.0,
                0.0,
                true,
            );

            return $result->asCacheHit((microtime(true) - $startedAt) * 1000);
        }

        $result = $this->inner->search($raw, $limit);

        try {
            $this->cache->put($key, [
                'query' => $result->query,
                'corrected_query' => $result->correctedQuery,
                'rows' => $result->suggestionArrays(),
            ], $this->ttl);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }
}
