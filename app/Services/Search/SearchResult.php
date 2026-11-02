<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Immutable result of an autocomplete search.
 */
final class SearchResult
{
    /**
     * @param string $query Normalized query.
     * @param string|null $correctedQuery Corrected query when typo correction was applied.
     * @param list<Suggestion> $suggestions Ranked suggestions.
     * @param float $tookMs Total service time in milliseconds.
     * @param float $dbMs Time spent in database queries.
     * @param bool $cacheHit Whether the result came from cache.
     */
    public function __construct(
        public readonly string $query,
        public readonly ?string $correctedQuery,
        public readonly array $suggestions,
        public readonly float $tookMs,
        public readonly float $dbMs,
        public readonly bool $cacheHit = false,
    ) {}

    /**
     * Build an empty result without querying the database.
     *
     * @param string $query Normalized query.
     * @return self
     */
    public static function empty(string $query): self
    {
        return new self($query, null, [], 0.0, 0.0);
    }

    /**
     * Return a copy marked as served from cache.
     *
     * @param float $tookMs Cache lookup duration.
     * @return self
     */
    public function asCacheHit(float $tookMs): self
    {
        return new self($this->query, $this->correctedQuery, $this->suggestions, $tookMs, 0.0, true);
    }

    /**
     * Convert suggestions to plain arrays for caching or transport.
     *
     * @return list<array{id:int,content:string,match:string}>
     */
    public function suggestionArrays(): array
    {
        return array_map(
            static fn (Suggestion $suggestion): array => $suggestion->toArray(),
            $this->suggestions
        );
    }
}
