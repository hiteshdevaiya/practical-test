<?php

declare(strict_types=1);

namespace App\Services\Usage;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Tracks informational (never billable) search request counts.
 *
 * The counter exists so the demo can prove that searching does not move the
 * billable usage total. Cache failures must never fail a search request.
 */
final class SearchCounter
{
    /**
     * @param CacheRepository $cache Cache store.
     */
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Increment the free search counter for a client.
     *
     * @param int $clientId API client id.
     * @return void
     */
    public function increment(int $clientId): void
    {
        try {
            $key = $this->key($clientId);

            // The database and file stores cannot increment a key that does
            // not exist yet, so create it first. "add" is a no-op when the
            // key is already present.
            $this->cache->add($key, 0, 3600);
            $this->cache->increment($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Read the free search counter for a client.
     *
     * @param int $clientId API client id.
     * @return int
     */
    public function get(int $clientId): int
    {
        try {
            return (int) $this->cache->get($this->key($clientId), 0);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    /**
     * Build the cache key for a client.
     *
     * @param int $clientId API client id.
     * @return string
     */
    private function key(int $clientId): string
    {
        return 'search-requests:'.$clientId;
    }
}
