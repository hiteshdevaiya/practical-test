<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Executes the read-only autocomplete search pipeline.
 *
 * The pipeline is:
 *   1. normalize and tokenize the query;
 *   2. run a strict token-AND match served by the B-tree word tables (an
 *      adjacent-pair lookup, then a rarest-word range scan), falling back to
 *      the trigram index for mid-word fragments;
 *   3. if too few rows were found, correct unknown tokens against the
 *      vocabulary and run the strict match again;
 *   4. merge exact matches before corrected matches, de-duplicated by id.
 */
final class AutocompleteSearch implements SearchService
{
    /**
     * Row estimate for a token the vocabulary does not know (a house number).
     */
    private const UNKNOWN_TOKEN_ROWS = 30000;

    /**
     * Driver rows probed by the word-driven plan, as a multiple of the cap.
     */
    private const DRIVER_SCAN_FACTOR = 10;

    /**
     * @param ConnectionInterface $connection Read-only search connection.
     * @param QueryNormalizer $normalizer Query normalizer.
     * @param TypoCorrector $corrector Vocabulary-based typo corrector.
     * @param int $candidateCap Maximum rows considered for ranking.
     * @param int $maxLimit Hard upper bound for the result limit.
     * @param int $minTokenLength Minimum indexable token length.
     * @param int $filterCandidateCap Candidate window for the word-table plans.
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly QueryNormalizer $normalizer,
        private readonly TypoCorrector $corrector,
        private readonly int $candidateCap,
        private readonly int $maxLimit,
        private readonly int $minTokenLength,
        private readonly int $filterCandidateCap,
    ) {}

    /**
     * Run the autocomplete search.
     *
     * @param string $raw Untrusted user input.
     * @param int $limit Desired number of suggestions.
     * @return SearchResult Ranked suggestions and timing metadata.
     */
    public function search(string $raw, int $limit): SearchResult
    {
        $startedAt = microtime(true);
        $dbMs = 0.0;

        $query = $this->normalizer->normalize($raw);
        $tokens = $this->normalizer->tokens($query);

        if (! $this->normalizer->hasIndexableToken($tokens)) {
            return new SearchResult(
                $query,
                null,
                [],
                (microtime(true) - $startedAt) * 1000,
                $dbMs,
            );
        }

        $limit = max(1, min($limit, $this->maxLimit));

        $rows = $this->strictMatch($query, $tokens, $limit, 'exact', $dbMs, false);
        $correctedQuery = null;

        // A record written after the vocabulary and word index were built can
        // still match the exact query even though no index knows the token.
        // Only then is the exact trigram scan worth its cost: proving that a
        // genuine typo matches nothing means a full scan at ten million rows.
        if (count($rows) < $limit && $this->derivedIndexesAreStale()) {
            $rows = $this->trigramMatch($query, $tokens, $limit, 'exact', $dbMs, $rows);
        }

        if (count($rows) < $limit) {
            $correctedTokens = $this->corrector->correct($tokens);

            if ($correctedTokens === null) {
                // Every token is a known word, so only a fragment from the
                // middle of a word can still match.
                $rows = $this->trigramMatch($query, $tokens, $limit, 'exact', $dbMs, $rows);
            } else {
                // A token that no vocabulary word contains cannot match any
                // record, so the exact trigram scan is skipped: it is the most
                // expensive plan and is guaranteed to find nothing.
                $correctedQuery = implode(' ', $correctedTokens);
                $correctedRows = $this->strictMatch($correctedQuery, $correctedTokens, $limit, 'corrected', $dbMs);
                $rows = $this->mergeUnique($rows, $correctedRows, $limit);
            }
        }

        $tookMs = (microtime(true) - $startedAt) * 1000;

        return new SearchResult($query, $correctedQuery, $rows, $tookMs, $dbMs);
    }

    /**
     * Execute one strict token-AND match, preferring the B-tree word index.
     *
     * The word tables answer a token-AND match from bounded index lookups,
     * which is what keeps ten million rows inside the latency budget. The
     * trigram plans in trigramMatch() are the fallback for mid-word fragments
     * and for data the word tables do not know about yet.
     *
     * @param string $query Query text used for ranking.
     * @param list<string> $tokens Tokens that must all appear.
     * @param int $limit Maximum rows to return.
     * @param string $matchType "exact" or "corrected".
     * @param float $dbMs Accumulator for database time.
     * @param bool $trigramFallback Whether to fall back to the trigram index
     *                              when the index stages return fewer than $limit rows.
     * @return list<Suggestion> Ranked suggestions.
     */
    private function strictMatch(
        string $query,
        array $tokens,
        int $limit,
        string $matchType,
        float &$dbMs,
        bool $trigramFallback = true,
    ): array {
        $rows = [];

        // Fast path: one index-only lookup in the B-tree prefix index.
        try {
            $rows = $this->runPrefixIndexMatch($query, $tokens, $limit, $matchType, $dbMs);

            if (count($rows) >= $limit) {
                return $rows;
            }
        } catch (QueryException $exception) {
            if (! $this->isStatementTimeout($exception) && ! $this->isMissingRelation($exception)) {
                throw $exception;
            }

            Log::warning('Prefix index unavailable; falling back to trigrams.', [
                'query' => $query,
                'match' => $matchType,
                'missing' => $this->isMissingRelation($exception),
            ]);
        }

        if (! $trigramFallback) {
            return $rows;
        }

        return $this->trigramMatch($query, $tokens, $limit, $matchType, $dbMs, $rows);
    }

    /**
     * Execute the trigram fallback, degrading when a plan times out.
     *
     * This is the only stage that can match a fragment from the middle of a
     * word, so it runs after the index stages could not fill the result.
     *
     * @param string $query Query text used for ranking.
     * @param list<string> $tokens Tokens that must all appear.
     * @param int $limit Maximum rows to return.
     * @param string $matchType "exact" or "corrected".
     * @param float $dbMs Accumulator for database time.
     * @param list<Suggestion> $rows Rows already found by earlier stages.
     * @return list<Suggestion> Ranked suggestions.
     */
    private function trigramMatch(
        string $query,
        array $tokens,
        int $limit,
        string $matchType,
        float &$dbMs,
        array $rows,
    ): array {
        $indexable = $this->indexableTokens($tokens);
        $hasShortTokens = count($indexable) !== count($tokens);

        // Each attempt is tried in order: the cheapest plan first, the
        // accurate plan only when the cheap one finds nothing, and the
        // degraded superset only when a cheaper plan timed out.
        $attempts = [];

        if ($hasShortTokens) {
            // Fast: indexable tokens select the candidates, short tokens are
            // applied to that bounded window.
            $attempts[] = [$tokens, false];
            // Accurate: every token filters the candidate scan itself.
            $attempts[] = [$tokens, true];
            // Degraded: drop the short tokens.
            $attempts[] = [$indexable, true];
        } else {
            $attempts[] = [$tokens, true];

            if (count($tokens) > 1) {
                // Degraded: the longest token alone is the most selective one.
                $attempts[] = [[$this->longestToken($tokens)], true];
            }
        }

        $attemptCount = count($attempts);

        foreach ($attempts as $index => [$attemptTokens, $shortInsideCandidate]) {
            $isLastAttempt = $index === $attemptCount - 1;

            try {
                $attemptRows = $this->runStrictMatch($query, $attemptTokens, $limit, $matchType, $dbMs, $shortInsideCandidate);
            } catch (QueryException $exception) {
                if (! $this->isStatementTimeout($exception)) {
                    throw $exception;
                }

                Log::warning($isLastAttempt
                    ? 'Search statement timed out on the last plan; returning what was found.'
                    : 'Search statement timed out; trying a cheaper plan.', [
                        'query' => $query,
                        'match' => $matchType,
                    ]);

                if ($isLastAttempt) {
                    // No cheaper plan is left. Returning the rows from earlier
                    // stages keeps a slow query from becoming a failed request.
                    return $rows;
                }

                continue;
            }

            // The bounded candidate window may have missed rows that satisfy a
            // rare short token; re-run with the short tokens inside the filter.
            if ($attemptRows === [] && ! $shortInsideCandidate && $hasShortTokens) {
                continue;
            }

            return $this->mergeUnique($rows, $attemptRows, $limit);
        }

        return $rows;
    }

    /**
     * Execute one strict match using the B-tree word index.
     *
     * Every word of every record is one row in record_words. A single token is
     * one range lookup (a complete word is a point range, partial input is a
     * prefix range). Several tokens scan the rarest token's range and prove
     * the remaining tokens with an EXISTS probe on (record_id, word).
     *
     * That keeps the work proportional to the rarest token instead of a whole
     * posting list, and it is independent of token order and distance: "hill
     * road" and "road hill" cost the same, whether the words are adjacent in
     * the record or not.
     *
     * @param string $query Query text used for ranking.
     * @param list<string> $tokens Tokens that must all appear.
     * @param int $limit Maximum rows to return.
     * @param string $matchType "exact" or "corrected".
     * @param float $dbMs Accumulator for database time.
     * @return list<Suggestion> Ranked suggestions.
     */
    private function runPrefixIndexMatch(
        string $query,
        array $tokens,
        int $limit,
        string $matchType,
        float &$dbMs,
    ): array {
        $startedAt = microtime(true);

        try {
            if (count($tokens) === 1) {
                return $this->executePrefixQuery(
                    'SELECT record_id FROM record_words WHERE word >= ? AND word < ? LIMIT ?',
                    [$tokens[0], $this->prefixUpperBound($tokens[0]), $this->filterCandidateCap],
                    $query,
                    $limit,
                    $matchType,
                );
            }

            $resolved = $this->resolveTerms($tokens);

            // Typing is progressive, so the tokens are usually neighbours in
            // the record. One adjacent-pair lookup answers that case directly.
            $rows = $this->adjacentPairMatch($tokens, $resolved, $query, $limit, $matchType);

            if (count($rows) >= $limit) {
                return $rows;
            }

            return $this->mergeUnique(
                $rows,
                $this->wordDrivenMatch($tokens, $resolved, $query, $limit, $matchType),
                $limit
            );
        } finally {
            $dbMs += (microtime(true) - $startedAt) * 1000;
        }
    }

    /**
     * Match tokens that are adjacent words in a record via record_word_pairs.
     *
     * The most selective token pairs are tried in both orientations, rarest
     * first. Every candidate already starts with the requested word prefixes
     * of its pair. Any remaining token is proven with an index probe inside
     * the pair scan, before the candidate cap applies, so a broad pair such as
     * "church lane" still yields the few records that also contain "bri".
     *
     * @param list<string> $tokens Tokens that must all appear.
     * @param array<string, int> $resolved Estimated record counts.
     * @param string $query Query text used for ranking.
     * @param int $limit Maximum rows to return.
     * @param string $matchType "exact" or "corrected".
     * @return list<Suggestion> Ranked suggestions.
     */
    private function adjacentPairMatch(
        array $tokens,
        array $resolved,
        string $query,
        int $limit,
        string $matchType,
    ): array {
        $rows = [];

        foreach (array_slice($this->tokenPairs($tokens, $resolved), 0, 3) as [$first, $second]) {
            $others = array_values(array_diff($tokens, [$first, $second]));
            $branch = $this->pairBranchSql(count($others));
            $candidateSql = 'SELECT record_id FROM (('.$branch.') UNION ('.$branch.')) u';

            $rows = $this->mergeUnique($rows, $this->executePrefixQuery(
                $candidateSql,
                [
                    ...$this->pairBranchBindings($first, $second, $others),
                    ...$this->pairBranchBindings($second, $first, $others),
                ],
                $query,
                $limit,
                $matchType,
            ), $limit);

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Match tokens in any order and at any distance via record_words.
     *
     * The least frequent token drives a range scan of the primary key, and
     * every other token is proven with an index probe on (record_id, word).
     * The probes are LATERAL ... LIMIT 1 joins on purpose: a plain EXISTS lets
     * the planner turn them into a semi-join that scans the other token's
     * whole range (measured: over 250 ms even when the driver matches nothing).
     * The driver range is scanned only until the candidate cap is reached.
     * Ordering that bounded set by id turns the join back to records into a
     * mostly sequential read, because the records heap is written in id order.
     *
     * @param list<string> $tokens Tokens that must all appear.
     * @param array<string, int> $resolved Estimated record counts.
     * @param string $query Query text used for ranking.
     * @param int $limit Maximum rows to return.
     * @param string $matchType "exact" or "corrected".
     * @return list<Suggestion> Ranked suggestions.
     */
    private function wordDrivenMatch(
        array $tokens,
        array $resolved,
        string $query,
        int $limit,
        string $matchType,
    ): array {
        $driver = $tokens[0];
        $driverRows = PHP_INT_MAX;

        foreach ($tokens as $token) {
            $rows = $this->estimatedRows($token, $resolved);

            if ($rows < $driverRows) {
                $driverRows = $rows;
                $driver = $token;
            }
        }

        $others = array_values(array_diff($tokens, [$driver]));

        // Only the first rows of the driver range are probed. A selective
        // driver is covered completely; for two common words the intersection
        // is found faster by the trigram fallback, so this stage must stay
        // cheap instead of walking tens of thousands of driver rows.
        $candidateSql = 'SELECT record_id FROM (
                             SELECT w.record_id
                             FROM (
                                 SELECT record_id FROM record_words
                                 WHERE word >= ? AND word < ? LIMIT ?
                             ) w'
                             .$this->probeJoins('w.record_id', count($others)).'
                             LIMIT ?
                         ) s ORDER BY record_id';

        return $this->executePrefixQuery(
            $candidateSql,
            [
                $driver,
                $this->prefixUpperBound($driver),
                $this->filterCandidateCap * self::DRIVER_SCAN_FACTOR,
                ...$this->probeBindings($others),
                $this->filterCandidateCap,
            ],
            $query,
            $limit,
            $matchType,
        );
    }

    /**
     * LATERAL joins proving that a record contains a word with each prefix.
     *
     * Each probe is one index-only seek on (record_id, word) per candidate row.
     *
     * @param string $recordColumn Column holding the candidate record id.
     * @param int $count Number of probes (one per remaining token).
     * @return string SQL fragment, to be placed before the WHERE clause.
     */
    private function probeJoins(string $recordColumn, int $count): string
    {
        $sql = '';

        for ($i = 0; $i < $count; $i++) {
            $sql .= " CROSS JOIN LATERAL (SELECT 1 FROM record_words x{$i}
                       WHERE x{$i}.record_id = {$recordColumn}
                         AND x{$i}.word >= ? AND x{$i}.word < ? LIMIT 1) e{$i}";
        }

        return $sql;
    }

    /**
     * Bindings for probeJoins(): a prefix range per token.
     *
     * @param list<string> $tokens Tokens to probe for.
     * @return list<string>
     */
    private function probeBindings(array $tokens): array
    {
        $bindings = [];

        foreach ($tokens as $token) {
            $bindings[] = $token;
            $bindings[] = $this->prefixUpperBound($token);
        }

        return $bindings;
    }

    /**
     * Bindings for one orientation of pairBranchSql().
     *
     * @param string $first Word prefix of the first word of the pair.
     * @param string $second Word prefix of the second word of the pair.
     * @param list<string> $others Remaining tokens, probed per candidate.
     * @return list<int|string>
     */
    private function pairBranchBindings(string $first, string $second, array $others): array
    {
        $firstHigh = $this->prefixUpperBound($first);
        $secondRange = [$second, $this->prefixUpperBound($second)];

        if ($others === []) {
            return [
                $first, $firstHigh, $firstHigh,
                ...$secondRange,
                $this->filterCandidateCap,
                $this->filterCandidateCap,
            ];
        }

        return [
            $first, $firstHigh, $firstHigh,
            ...$secondRange,
            $this->filterCandidateCap * self::DRIVER_SCAN_FACTOR,
            ...$this->probeBindings($others),
            $this->filterCandidateCap,
            $this->filterCandidateCap,
        ];
    }

    /**
     * Estimated number of records containing a word with this prefix.
     *
     * The vocabulary deliberately leaves out numbers, so an unknown token is
     * usually a house number. Each number (1 to 300) occurs in tens of
     * thousands of records at ten million rows, which is as common as a name,
     * so it must not be mistaken for the rarest token.
     *
     * @param string $token Normalized token.
     * @param array<string, int> $resolved Estimated record counts.
     * @return int
     */
    private function estimatedRows(string $token, array $resolved): int
    {
        $rows = $resolved[$token] ?? 0;

        return $rows > 0 ? $rows : self::UNKNOWN_TOKEN_ROWS;
    }

    /**
     * SQL for one orientation of an adjacent-pair lookup.
     *
     * The primary key is (w1, w2, record_id). A plain range on w1 would make
     * PostgreSQL read every entry of every matching first word before it could
     * test w2 (about 33,000 entries for a house number at ten million rows).
     * Instead the distinct first words inside the range are enumerated with
     * one cheap seek each (a loose index scan), and every first word is then
     * probed with an equality on w1 plus a range on w2, which is a direct
     * seek. Remaining tokens are probed per candidate inside that seek.
     * Bindings: see pairBranchBindings().
     *
     * @param int $probes Number of remaining tokens to probe for.
     * @return string SQL selecting record ids.
     */
    private function pairBranchSql(int $probes): string
    {
        // With remaining tokens to prove, only a bounded window of the pair is
        // probed: a broad pair holds tens of thousands of rows, and walking all
        // of them for a rare third token costs more than the trigram fallback.
        $pairs = $probes === 0
            ? 'SELECT pp.record_id FROM record_word_pairs pp
               WHERE pp.w1 = f.w AND pp.w2 >= ? AND pp.w2 < ? LIMIT ?'
            : 'SELECT pp.record_id
               FROM (
                   SELECT record_id FROM record_word_pairs
                   WHERE w1 = f.w AND w2 >= ? AND w2 < ? LIMIT ?
               ) pp'.$this->probeJoins('pp.record_id', $probes).'
               LIMIT ?';

        return 'WITH RECURSIVE firsts(w) AS (
                    (SELECT w1 FROM record_word_pairs WHERE w1 >= ? AND w1 < ? ORDER BY w1 LIMIT 1)
                    UNION ALL
                    SELECT (SELECT w1 FROM record_word_pairs WHERE w1 > f.w AND w1 < ? ORDER BY w1 LIMIT 1)
                    FROM firsts f WHERE f.w IS NOT NULL
                )
                SELECT p.record_id
                FROM firsts f
                CROSS JOIN LATERAL ('.$pairs.') p
                WHERE f.w IS NOT NULL
                LIMIT ?';
    }

    /**
     * Order token pairs by combined estimated frequency, rarest first.
     *
     * @param list<string> $tokens Normalized tokens.
     * @param array<string, int> $resolved Estimated record counts.
     * @return list<array{0: string, 1: string}> Token pairs.
     */
    private function tokenPairs(array $tokens, array $resolved): array
    {
        $pairs = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pairs[] = [
                    $tokens[$i],
                    $tokens[$j],
                    $this->estimatedRows($tokens[$i], $resolved) + $this->estimatedRows($tokens[$j], $resolved),
                ];
            }
        }

        usort($pairs, static fn (array $left, array $right): int => $left[2] <=> $right[2]);

        return array_map(static fn (array $pair): array => [$pair[0], $pair[1]], $pairs);
    }

    /**
     * Smallest string that sorts after every string starting with a prefix.
     *
     * Used to turn a word prefix into a B-tree range. Tokens are lower-cased
     * alphanumerics after normalization, so incrementing the final code point
     * is enough to close the range.
     *
     * @param string $prefix Word prefix.
     * @return string Exclusive upper bound.
     */
    private function prefixUpperBound(string $prefix): string
    {
        if ($prefix === '') {
            return $prefix;
        }

        $characters = preg_split('//u', $prefix, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($characters === []) {
            return $prefix;
        }

        $last = array_pop($characters);
        $characters[] = mb_chr(mb_ord($last, 'UTF-8') + 1, 'UTF-8');

        return implode('', $characters);
    }

    /**
     * Join a bounded candidate id set back to records and rank it.
     *
     * @param string $candidateSql Candidate id query.
     * @param list<mixed> $candidateBindings Bindings for that query.
     * @param string $query Query text used for ranking.
     * @param int $limit Maximum rows to return.
     * @param string $matchType "exact" or "corrected".
     * @return list<Suggestion> Ranked suggestions.
     */
    private function executePrefixQuery(
        string $candidateSql,
        array $candidateBindings,
        string $query,
        int $limit,
        string $matchType,
    ): array {
        // Every candidate already satisfies every token, so the join is a
        // primary-key lookup per candidate. OFFSET 0 keeps it that way: it
        // stops the planner from rewriting the join into a scan of records.
        $sql = 'WITH candidate_ids AS ('.$candidateSql.')
                SELECT r.id, r.content, word_similarity(?, r.content) AS score, ?::text AS match_type
                FROM (
                    SELECT records.id, records.content
                    FROM records
                    JOIN candidate_ids c ON c.record_id = records.id
                    OFFSET 0
                ) r
                ORDER BY (lower(r.content) LIKE ?) DESC, score DESC, length(r.content), r.id
                LIMIT ?';

        $rows = $this->connection->select($sql, [
            ...$candidateBindings,
            $query,
            $matchType,
            $query.'%',
            $limit,
        ]);

        return array_map(
            static fn (object $row): Suggestion => new Suggestion(
                (int) $row->id,
                (string) $row->content,
                $matchType,
                (float) $row->score,
            ),
            $rows
        );
    }

    /**
     * Estimate how many records contain a word starting with each token.
     *
     * The estimate sums the vocabulary frequencies of every word with that
     * prefix. Tokens the vocabulary does not know report zero.
     *
     * @param list<string> $tokens Normalized tokens.
     * @return array<string, int> Estimated record count, keyed by token.
     */
    private function resolveTerms(array $tokens): array
    {
        $rows = $this->connection->select(
            "SELECT t.token,
                    (SELECT coalesce(sum(v.row_count), 0) FROM search_vocabulary v WHERE v.word LIKE t.token || '%') AS rows
             FROM unnest(string_to_array(?, ' ')) AS t(token)",
            [implode(' ', $tokens)]
        );

        $resolved = [];

        foreach ($rows as $row) {
            $resolved[(string) $row->token] = (int) $row->rows;
        }

        return $resolved;
    }

    /**
     * Whether records were added after the derived indexes were built.
     *
     * The vocabulary and the word index are derived data. Record ids only
     * grow, so a record id above the highest indexed one means rows exist that
     * no index stage can see. Both lookups are index-only and constant time,
     * unlike a trigram probe, which must scan to prove a typo matches nothing.
     * Updates or deletes of existing rows are not detected; rebuild with
     * `php artisan search:build-prefix-index` after changing records.
     *
     * @return bool True when records are newer than the word index.
     */
    private function derivedIndexesAreStale(): bool
    {
        $latest = $this->connection->selectOne(
            'SELECT (SELECT max(id) FROM records) AS records_max,
                    (SELECT max(record_id) FROM record_words) AS indexed_max'
        );

        return (int) ($latest->records_max ?? 0) > (int) ($latest->indexed_max ?? 0);
    }

    /**
     * Execute one strict match with the given tokens.
     *
     * This is the trigram fallback. Tokens of at least the minimum length use
     * the GIN trigram index. Shorter tokens (house numbers, a one-letter
     * fragment of the next word) cannot use a trigram index, so they are
     * applied to the already bounded candidate set; if that returns nothing,
     * the accurate (slower) plan is used once. A statement timeout degrades
     * the remaining attempts to a superset without the short tokens.
     *
     * @param string $query Query text used for ranking.
     * @param list<string> $tokens Tokens that must all appear.
     * @param int $limit Maximum rows to return.
     * @param string $matchType "exact" or "corrected".
     * @param float $dbMs Accumulator for database time.
     * @param bool $shortInsideCandidate Keep short tokens inside the candidate query.
     * @return list<Suggestion> Ranked suggestions.
     */
    private function runStrictMatch(
        string $query,
        array $tokens,
        int $limit,
        string $matchType,
        float &$dbMs,
        bool $shortInsideCandidate,
    ): array {
        $candidateConditions = [];
        $candidateBindings = [];
        $filterConditions = [];
        $filterBindings = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) >= $this->minTokenLength) {
                $candidateConditions[] = 'content ILIKE ?';
                $candidateBindings[] = '%'.$token.'%';
            } elseif ($shortInsideCandidate) {
                $candidateConditions[] = "(' ' || lower(content)) LIKE ?";
                $candidateBindings[] = '% '.$token.'%';
            } else {
                $filterConditions[] = "(' ' || lower(content)) LIKE ?";
                $filterBindings[] = '% '.$token.'%';
            }
        }

        if ($candidateConditions === []) {
            // Only short tokens: the padded filter is the only predicate left.
            $candidateConditions = $filterConditions;
            $candidateBindings = $filterBindings;
            $filterConditions = [];
            $filterBindings = [];
        }

        if ($candidateConditions === []) {
            return [];
        }

        $sql = 'WITH candidates AS (
                    SELECT id, content
                    FROM records
                    WHERE '.implode(' AND ', $candidateConditions).'
                    LIMIT ?
                )
                SELECT id, content, word_similarity(?, content) AS score, ?::text AS match_type
                FROM candidates
                '.($filterConditions === [] ? '' : 'WHERE '.implode(' AND ', $filterConditions)).'
                ORDER BY (lower(content) LIKE ?) DESC, score DESC, length(content), id
                LIMIT ?';

        $queryStartedAt = microtime(true);

        try {
            $rows = $this->connection->select($sql, [
                ...$candidateBindings,
                $this->candidateCap,
                $query,
                $matchType,
                ...$filterBindings,
                $query.'%',
                $limit,
            ]);
        } finally {
            $dbMs += (microtime(true) - $queryStartedAt) * 1000;
        }

        return array_map(
            static fn (object $row): Suggestion => new Suggestion(
                (int) $row->id,
                (string) $row->content,
                $matchType,
                (float) $row->score,
            ),
            $rows
        );
    }

    /**
     * Keep only the tokens that can use a trigram index.
     *
     * @param list<string> $tokens Normalized tokens.
     * @return list<string> Tokens at least the minimum length.
     */
    private function indexableTokens(array $tokens): array
    {
        return array_values(array_filter(
            $tokens,
            fn (string $token): bool => mb_strlen($token) >= $this->minTokenLength,
        ));
    }

    /**
     * Pick the longest token; the first one wins a tie.
     *
     * @param list<string> $tokens Normalized tokens.
     * @return string
     */
    private function longestToken(array $tokens): string
    {
        $longest = $tokens[0];

        foreach ($tokens as $token) {
            if (mb_strlen($token) > mb_strlen($longest)) {
                $longest = $token;
            }
        }

        return $longest;
    }

    /**
     * Determine whether an exception is a PostgreSQL statement timeout.
     *
     * @param QueryException $exception Database exception.
     * @return bool
     */
    private function isStatementTimeout(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'statement timeout')
            || ($exception->errorInfo[0] ?? null) === '57014';
    }

    /**
     * Determine whether an exception means a derived table does not exist.
     *
     * This keeps the search path working before the prefix-index migration is
     * applied, or on a database where it was rolled back.
     *
     * @param QueryException $exception Database exception.
     * @return bool
     */
    private function isMissingRelation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '42P01'
            || str_contains($exception->getMessage(), 'does not exist');
    }

    /**
     * Merge two suggestion lists, keeping the first occurrence of each id.
     *
     * @param list<Suggestion> $primary Higher priority suggestions.
     * @param list<Suggestion> $secondary Lower priority suggestions.
     * @param int $limit Maximum merged size.
     * @return list<Suggestion> De-duplicated suggestions.
     */
    private function mergeUnique(array $primary, array $secondary, int $limit): array
    {
        $seen = [];
        $merged = [];

        foreach ([...$primary, ...$secondary] as $suggestion) {
            if (isset($seen[$suggestion->id])) {
                continue;
            }

            $seen[$suggestion->id] = true;
            $merged[] = $suggestion;

            if (count($merged) >= $limit) {
                break;
            }
        }

        return $merged;
    }
}
