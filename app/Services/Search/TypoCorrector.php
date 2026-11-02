<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Corrects unknown query tokens against the small search vocabulary.
 *
 * Fuzzy matching never runs against the ten-million-row records table: it
 * scans a few thousand vocabulary words, keeping typo handling cheap.
 */
final class TypoCorrector
{
    /**
     * @param ConnectionInterface $connection Read-only search connection.
     * @param int $minTokenLength Minimum token length for correction.
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $minTokenLength,
    ) {}

    /**
     * Return tokens with unknown words replaced by their closest vocabulary
     * match, or null when nothing was corrected.
     *
     * @param list<string> $tokens Normalized query tokens.
     * @return list<string>|null Corrected tokens, or null when unchanged.
     */
    public function correct(array $tokens): ?array
    {
        $candidates = array_values(array_filter(
            $tokens,
            fn (string $token): bool => mb_strlen($token) >= $this->minTokenLength
        ));

        if ($candidates === []) {
            return null;
        }

        $sql = <<<'SQL'
            SELECT t.token, c.word
            FROM unnest(string_to_array(?, ' ')) AS t(token)
            CROSS JOIN LATERAL (
                SELECT v.word
                FROM search_vocabulary v
                WHERE v.word % t.token
                ORDER BY similarity(v.word, t.token) DESC, v.row_count DESC
                LIMIT 1
            ) AS c
            WHERE NOT EXISTS (
                SELECT 1
                FROM search_vocabulary k
                WHERE k.word LIKE '%' || t.token || '%'
            )
        SQL;

        try {
            $rows = $this->connection->select($sql, [implode(' ', $candidates)]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($rows === []) {
            return null;
        }

        $replacements = [];

        foreach ($rows as $row) {
            $replacements[(string) $row->token] = (string) $row->word;
        }

        $corrected = array_map(
            static fn (string $token): string => $replacements[$token] ?? $token,
            $tokens
        );

        return $corrected === $tokens ? null : $corrected;
    }
}
