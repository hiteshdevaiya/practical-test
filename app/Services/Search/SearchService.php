<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Contract implemented by every autocomplete search implementation.
 */
interface SearchService
{
    /**
     * Search records for the given raw query.
     *
     * @param string $raw Untrusted user input.
     * @param int $limit Maximum number of suggestions.
     * @return SearchResult Ranked result with timing metadata.
     */
    public function search(string $raw, int $limit): SearchResult;
}
