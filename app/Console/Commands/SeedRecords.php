<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\PrefixIndexBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates the searchable records dataset and all derived database objects.
 *
 * Rows are produced entirely inside PostgreSQL with set-based SQL, so memory
 * usage stays flat regardless of the requested row count. The command also
 * rebuilds the trigram index, the typo-correction vocabulary and planner
 * statistics, then optionally prewarms the data into shared buffers.
 */
final class SeedRecords extends Command
{
    /**
     * @var string
     */
    protected $signature = 'records:seed
        {--count=10000 : Total number of rows to generate}
        {--batch=100000 : Rows inserted per batch}
        {--fresh : Truncate existing records and usage events before seeding}
        {--skip-prewarm : Skip pg_prewarm after seeding}
        {--force : Allow destructive --fresh runs in production}
        {--index-memory=1GB : maintenance_work_mem used while building the GIN index}';

    /**
     * @var string
     */
    protected $description = 'Seed records, rebuild the trigram index, vocabulary and statistics';

    /**
     * Execute the command.
     *
     * @return int Exit code.
     */
    public function handle(): int
    {
        $count = (int) $this->option('count');
        $batchSize = (int) $this->option('batch');
        $fresh = (bool) $this->option('fresh');
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('This command requires the PostgreSQL connection.');

            return self::FAILURE;
        }

        if ($count < 1 || $batchSize < 1) {
            $this->error('--count and --batch must both be positive integers.');

            return self::FAILURE;
        }

        if ($fresh && app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to truncate production data without --force.');

            return self::FAILURE;
        }

        $existing = (int) $connection->table('records')->count();

        if ($existing > 0 && ! $fresh) {
            $this->error("The records table already contains {$existing} rows. Re-run with --fresh to rebuild it.");

            return self::FAILURE;
        }

        if ($existing > 0 && ! $this->confirmFresh($existing)) {
            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $connection->disableQueryLog();

        try {
            $this->prepare($fresh);
            $this->insertBatches($count, $batchSize);
            $this->buildIndex((string) $this->option('index-memory'));
            $this->buildVocabulary();
            $this->buildPrefixIndex();
            $this->refreshStatistics();

            if (! $this->option('skip-prewarm')) {
                $this->prewarm();
            }
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error('Seeding failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $duration = microtime(true) - $startedAt;
        $this->reportResults($count, $duration);

        return self::SUCCESS;
    }

    /**
     * Confirm a destructive rebuild of existing data.
     *
     * @param int $existing Number of existing rows.
     * @return bool True when the run may continue.
     */
    private function confirmFresh(int $existing): bool
    {
        $this->warn("This will delete {$existing} records and all recorded usage events.");

        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm('Continue?', false);
    }

    /**
     * Truncate stale data and tune the session for a bulk load.
     *
     * @param bool $fresh Whether to truncate existing data.
     * @return void
     */
    private function prepare(bool $fresh): void
    {
        $connection = DB::connection();

        $connection->statement('SET synchronous_commit = off');
        $connection->statement("SET work_mem = '64MB'");

        // Bulk loads must not maintain the GIN indexes; they are rebuilt afterwards.
        $connection->statement('DROP INDEX IF EXISTS records_content_trgm_idx');
        $connection->statement('DROP INDEX IF EXISTS records_content_padded_trgm_idx');

        if (! $fresh) {
            return;
        }

        $connection->transaction(function (): void {
            // CASCADE clears dependent usage events as well.
            DB::statement('TRUNCATE TABLE records RESTART IDENTITY CASCADE');
            DB::statement('TRUNCATE TABLE search_vocabulary');
            DB::table('api_clients')->update(['billable_usage_count' => 0, 'updated_at' => now()]);
        });
    }

    /**
     * Insert rows in bounded batches using only database-side generation.
     *
     * @param int $count Total rows to insert.
     * @param int $batchSize Rows per statement.
     * @return void
     */
    private function insertBatches(int $count, int $batchSize): void
    {
        $words = config('seed_words');
        $names = $this->toPgArray($words['names']);
        $types = $this->toPgArray($words['types']);
        $towns = $this->toPgArray($words['towns']);
        $areas = $this->toPgArray($words['areas']);

        $sql = <<<'SQL'
            INSERT INTO records (content)
            SELECT concat_ws(' ',
                1 + floor(random() * 300)::int,
                w.names[1 + floor(random() * cardinality(w.names))::int],
                w.types[1 + floor(random() * cardinality(w.types))::int],
                w.towns[1 + floor(random() * cardinality(w.towns))::int],
                CASE WHEN random() < 0.5
                     THEN w.areas[1 + floor(random() * cardinality(w.areas))::int]
                END)
            FROM generate_series(1, ?::int) AS g
            CROSS JOIN (
                SELECT ?::text[] AS names, ?::text[] AS types,
                       ?::text[] AS towns, ?::text[] AS areas
            ) AS w
        SQL;

        $batches = (int) ceil($count / $batchSize);
        $remaining = $count;
        $progress = $this->output->createProgressBar($batches);
        $progress->setFormat(' %current%/%max% batches [%bar%] %percent:3s%% %elapsed:6s%');
        $progress->start();

        for ($i = 0; $i < $batches; $i++) {
            $rows = min($batchSize, $remaining);

            DB::connection()->insert($sql, [$rows, $names, $types, $towns, $areas]);

            $remaining -= $rows;
            $progress->advance();
        }

        $progress->finish();
        $this->newLine();
        $this->info("Inserted {$count} rows.");
    }

    /**
     * Build the GIN trigram index after the bulk load for speed.
     *
     * @param string $maintenanceMemory maintenance_work_mem value.
     * @return void
     */
    private function buildIndex(string $maintenanceMemory): void
    {
        $connection = DB::connection();
        $this->info('Building trigram indexes...');

        $startedAt = microtime(true);
        $connection->statement('DROP INDEX IF EXISTS records_content_trgm_idx');
        $connection->statement('DROP INDEX IF EXISTS records_content_padded_trgm_idx');

        $maintenanceMemory = $this->clampMaintenanceMemory($maintenanceMemory);
        $this->line("maintenance_work_mem = {$maintenanceMemory}");
        $connection->statement('SET maintenance_work_mem = '."'".$this->escapeLiteral($maintenanceMemory)."'");
        $connection->statement(
            'CREATE INDEX records_content_trgm_idx
             ON records USING GIN (content gin_trgm_ops)
             WITH (fastupdate = off)'
        );
        // House numbers and other short fragments are matched against
        // " content" so they start on a word boundary. Indexing the padded,
        // lower-cased expression lets those patterns use a trigram index
        // instead of filtering thousands of heap rows.
        $connection->statement(
            "CREATE INDEX records_content_padded_trgm_idx
             ON records USING GIN ((' ' || lower(content)) gin_trgm_ops)
             WITH (fastupdate = off)"
        );

        $this->line(sprintf('Indexes built in %.1fs.', microtime(true) - $startedAt));
    }

    /**
     * Rebuild the vocabulary table from the seeded records.
     *
     * @return void
     */
    private function buildVocabulary(): void
    {
        $connection = DB::connection();
        $this->info('Building typo-correction vocabulary...');

        $connection->statement('TRUNCATE TABLE search_vocabulary');

        $connection->statement(<<<'SQL'
            INSERT INTO search_vocabulary (word, row_count)
            SELECT word, count(*)
            FROM records, regexp_split_to_table(lower(content), '\s+') AS word
            WHERE length(word) >= 3 AND word !~ '^\d+$'
            GROUP BY word
        SQL);

        $connection->statement(
            'CREATE INDEX IF NOT EXISTS search_vocabulary_word_trgm_idx
             ON search_vocabulary USING GIN (word gin_trgm_ops)'
        );

        $this->line('Vocabulary words: '.$connection->table('search_vocabulary')->count());
    }

    /**
     * Rebuild the B-tree word index from the freshly seeded records.
     *
     * @return void
     */
    private function buildPrefixIndex(): void
    {
        $this->info('Rebuilding search word index...');

        $result = app(PrefixIndexBuilder::class)->rebuild();

        $this->line('Index rows: '.number_format($result['words']).' word / '.number_format($result['pairs']).' pair');
    }

    /**
     * Refresh planner statistics and the visibility map.
     *
     * @return void
     */
    private function refreshStatistics(): void
    {
        $this->info('Running VACUUM (ANALYZE)...');

        // Trigram selectivity estimates are notoriously coarse with the
        // default statistics target (100). A higher target makes the planner
        // distinguish a token that matches 0.6% of rows from one that matches
        // 4%, which is the difference between a ~4 ms bitmap index scan and a
        // ~70 ms sequential scan for the candidate query.
        DB::connection()->statement('ALTER TABLE records ALTER COLUMN content SET STATISTICS 1000');
        DB::connection()->statement('VACUUM (ANALYZE) records');
        DB::connection()->statement('VACUUM (ANALYZE) search_vocabulary');

        // Sets the visibility map so word-table lookups are index-only.
        DB::connection()->statement('VACUUM (ANALYZE) record_words');
        DB::connection()->statement('VACUUM (ANALYZE) record_word_pairs');
    }

    /**
     * Load the search tables and indexes into the cache when pg_prewarm exists.
     *
     * Uses the "read" mode, which fills the operating system cache. The data is
     * several times larger than a default shared_buffers, so loading it into
     * PostgreSQL's own buffers would only evict what was just read.
     *
     * @return void
     */
    private function prewarm(): void
    {
        $relations = [
            'records',
            'records_content_trgm_idx',
            'records_content_padded_trgm_idx',
            'record_words',
            'record_words_pkey',
            'record_words_record_idx',
            'record_word_pairs',
            'record_word_pairs_pkey',
        ];

        try {
            DB::connection()->statement('CREATE EXTENSION IF NOT EXISTS pg_prewarm');

            foreach ($relations as $relation) {
                DB::connection()->select("SELECT pg_prewarm(?::regclass, 'read')", [$relation]);
            }

            $this->line('Prewarmed records, trigram indexes and word tables.');
        } catch (Throwable $exception) {
            $this->warn('pg_prewarm unavailable: '.$exception->getMessage());
        }
    }

    /**
     * Print dataset verification metrics.
     *
     * @param int $expected Expected row count.
     * @param float $duration Seconds spent.
     * @return void
     */
    private function reportResults(int $expected, float $duration): void
    {
        $connection = DB::connection();
        $actual = (int) $connection->table('records')->count();
        $distribution = $connection->select(<<<'SQL'
            SELECT array_length(regexp_split_to_array(content, '\s+'), 1) AS words, count(*) AS total
            FROM records
            GROUP BY 1
            ORDER BY 1
        SQL);

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows requested', number_format($expected)],
                ['Rows present', number_format($actual)],
                ['Duration', sprintf('%.1fs', $duration)],
                ['Peak PHP memory', sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576)],
                ['Table size', (string) $connection->selectOne("SELECT pg_size_pretty(pg_relation_size('records')) AS size")->size],
                ['Index size', (string) $connection->selectOne("SELECT pg_size_pretty(pg_relation_size('records_content_trgm_idx') + pg_relation_size('records_content_padded_trgm_idx')) AS size")->size],
            ]
        );

        foreach ($distribution as $row) {
            $this->line(sprintf('  %s-word rows: %s', $row->words, number_format((int) $row->total)));
        }

        if ($actual !== $expected) {
            $this->warn('Row count differs from the requested count.');
        }
    }

    /**
     * Convert a PHP array into a PostgreSQL array literal.
     *
     * @param array<int, string> $values Trusted word list.
     * @return string
     */
    private function toPgArray(array $values): string
    {
        $escaped = array_map(
            static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            $values
        );

        return '{'.implode(',', $escaped).'}';
    }

    /**
     * Clamp the requested maintenance_work_mem to the server's maximum.
     *
     * PostgreSQL rejects values above 2 GB minus 1 kB, so the documented
     * `--index-memory=2GB` would otherwise fail after the bulk insert.
     *
     * @param string $requested Value such as "1GB", "512MB" or "1048576kB".
     * @return string
     */
    private function clampMaintenanceMemory(string $requested): string
    {
        $setting = DB::connection()->selectOne(
            "SELECT max_val FROM pg_settings WHERE name = 'maintenance_work_mem'"
        );
        $maxKb = isset($setting->max_val) && is_numeric($setting->max_val)
            ? (int) $setting->max_val
            : 0;

        if ($maxKb <= 0 || preg_match('/^\s*(\d+)\s*(kB|MB|GB)?\s*$/i', $requested, $matches) !== 1) {
            return $requested;
        }

        $value = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? 'kB');
        $requestedKb = match ($unit) {
            'GB' => $value * 1024 * 1024,
            'MB' => $value * 1024,
            default => $value,
        };

        return $requestedKb > $maxKb ? $maxKb.'kB' : $requested;
    }

    /**
     * Escape a value used inside a SQL literal that cannot be bound.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function escapeLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
