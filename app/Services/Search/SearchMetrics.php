<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Collects per-request search timings for the Server-Timing response header.
 *
 * The search controller writes into this collector after the search pipeline
 * finishes, and the response middleware reads it afterwards. A container
 * singleton is required because Laravel resolves a FormRequest as a *copy* of
 * the current request: attributes written on that copy never reach the outer
 * middleware. The middleware resets the collector at the start of every
 * request, so long-lived workers (for example Octane) cannot leak metrics
 * between requests.
 */
final class SearchMetrics
{
    /**
     * Database time for the current request in milliseconds.
     */
    private ?float $dbMs = null;

    /**
     * Cache outcome for the current request ("hit", "miss" or "off").
     */
    private ?string $cache = null;

    /**
     * Clear the collected metrics before handling a new request.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->dbMs = null;
        $this->cache = null;
    }

    /**
     * Store the metrics of a completed search.
     *
     * @param float $dbMs Database time in milliseconds.
     * @param string $cache Cache outcome label.
     * @return void
     */
    public function record(float $dbMs, string $cache): void
    {
        $this->dbMs = $dbMs;
        $this->cache = $cache;
    }

    /**
     * Determine whether a search already reported metrics.
     *
     * @return bool
     */
    public function hasMetrics(): bool
    {
        return $this->dbMs !== null || $this->cache !== null;
    }

    /**
     * Get the measured database time.
     *
     * @return float|null Milliseconds, or null when no search ran.
     */
    public function dbMs(): ?float
    {
        return $this->dbMs;
    }

    /**
     * Get the cache outcome label.
     *
     * @return string|null "hit", "miss" or "off"; null when no search ran.
     */
    public function cache(): ?string
    {
        return $this->cache;
    }
}
