<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * Normalizes raw user input into a safe, bounded search query.
 *
 * Only letters, numbers and single spaces survive normalization, which makes
 * SQL LIKE wildcard injection impossible before values are bound.
 */
final class QueryNormalizer
{
    /**
     * @param int $maxLength Maximum normalized query length in characters.
     * @param int $maxTokens Maximum number of distinct tokens kept.
     * @param int $minTokenLength Minimum length for a trigram-indexable token.
     */
    public function __construct(
        private readonly int $maxLength,
        private readonly int $maxTokens,
        private readonly int $minTokenLength,
    ) {}

    /**
     * Normalize a raw query string.
     *
     * Trims, lowercases, replaces punctuation with spaces, collapses
     * whitespace and caps the length.
     *
     * @param string $raw Untrusted user input.
     * @return string Normalized query.
     */
    public function normalize(string $raw): string
    {
        $value = mb_strtolower(trim($raw));

        // Remove LIKE wildcards and any other punctuation by replacement.
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        return mb_substr($value, 0, $this->maxLength);
    }

    /**
     * Split a normalized query into unique tokens.
     *
     * @param string $normalized Normalized query.
     * @return list<string> Unique tokens, capped at the configured maximum.
     */
    public function tokens(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }

        $tokens = array_values(array_unique(explode(' ', $normalized)));

        return array_slice($tokens, 0, $this->maxTokens);
    }

    /**
     * Determine whether at least one token can use the trigram index.
     *
     * Queries made only of very short fragments (for example "25") are
     * rejected before touching the database.
     *
     * @param list<string> $tokens Normalized tokens.
     * @return bool True when a token meets the minimum length.
     */
    public function hasIndexableToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (mb_strlen($token) >= $this->minTokenLength) {
                return true;
            }
        }

        return false;
    }
}
