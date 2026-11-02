<?php

declare(strict_types=1);

namespace App\Services\Search;

use Closure;
use Illuminate\Database\ConnectionInterface;

/**
 * Builds the B-tree word tables from the records table.
 *
 * Both tables are derived data: they are truncated and rebuilt from scratch so
 * they can never drift from records. Their indexes are dropped during the bulk
 * load and recreated afterwards, which is much faster than maintaining them
 * row by row.
 *
 *   record_words       (word, record_id)         one row per word in a record
 *   record_word_pairs  (w1, w2, record_id)       one row per adjacent word pair
 */
final class PrefixIndexBuilder
{
    /**
     * @param ConnectionInterface $connection Trusted read-write connection.
     */
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Rebuild both word tables.
     *
     * @return array{words: int, pairs: int} Inserted row counts.
     */
    public function rebuild(): array
    {
        return [
            'words' => $this->rebuildWords(),
            'pairs' => $this->rebuildPairs(),
        ];
    }

    /**
     * Rebuild record_words and its two indexes.
     *
     * @return int Inserted row count.
     */
    public function rebuildWords(): int
    {
        return $this->withBulkLoad('record_words', function (): int {
            $this->connection->statement('TRUNCATE record_words');
            $this->connection->statement('ALTER TABLE record_words DROP CONSTRAINT IF EXISTS record_words_pkey');
            $this->connection->statement('DROP INDEX IF EXISTS record_words_record_idx');

            $words = $this->connection->affectingStatement(<<<'SQL'
                INSERT INTO record_words (record_id, word)
                SELECT DISTINCT id, w
                FROM records, regexp_split_to_table(lower(content), '\s+') AS w
                WHERE w <> ''
            SQL);

            $this->connection->statement('ALTER TABLE record_words ADD PRIMARY KEY (word, record_id)');
            $this->connection->statement(
                'CREATE INDEX record_words_record_idx
                 ON record_words (record_id, word) WITH (fillfactor = 100)'
            );
            $this->connection->statement('ANALYZE record_words');

            return $words;
        });
    }

    /**
     * Rebuild record_word_pairs and its primary key.
     *
     * @return int Inserted row count.
     */
    public function rebuildPairs(): int
    {
        return $this->withBulkLoad('record_word_pairs', function (): int {
            $this->connection->statement('TRUNCATE record_word_pairs');
            $this->connection->statement('ALTER TABLE record_word_pairs DROP CONSTRAINT IF EXISTS record_word_pairs_pkey');

            $pairs = $this->connection->affectingStatement(<<<'SQL'
                INSERT INTO record_word_pairs (record_id, w1, w2)
                SELECT DISTINCT record_id, w1, w2
                FROM (
                    SELECT id AS record_id, w AS w1,
                           lead(w) OVER (PARTITION BY id ORDER BY ord) AS w2
                    FROM records,
                         regexp_split_to_table(lower(content), '\s+') WITH ORDINALITY AS t(w, ord)
                    WHERE w <> ''
                ) s
                WHERE w2 IS NOT NULL
            SQL);

            $this->connection->statement('ALTER TABLE record_word_pairs ADD PRIMARY KEY (w1, w2, record_id)');
            $this->connection->statement('ANALYZE record_word_pairs');

            return $pairs;
        });
    }

    /**
     * Run a bulk load with autovacuum paused and bulk-friendly session settings.
     *
     * Autovacuum can hold a lock on the table while the load runs and then
     * block the PRIMARY KEY build for hours, and a small work_mem makes the
     * DISTINCT sort spill and turns the load from minutes into hours. The
     * session settings only affect this connection.
     *
     * @param string $table Table being rebuilt.
     * @param Closure(): int $load Loads the table and returns the row count.
     * @return int Inserted row count.
     */
    private function withBulkLoad(string $table, Closure $load): int
    {
        $this->connection->statement("ALTER TABLE {$table} SET (autovacuum_enabled = false)");
        $this->connection->statement("SET maintenance_work_mem = '512MB'");
        $this->connection->statement("SET work_mem = '512MB'");
        $this->connection->statement('SET synchronous_commit = off');

        try {
            return $load();
        } finally {
            $this->connection->statement("ALTER TABLE {$table} SET (autovacuum_enabled = true)");
        }
    }
}
