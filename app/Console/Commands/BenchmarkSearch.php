<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\AutocompleteSearch;
use App\Services\Search\SearchResult;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Measures the uncached search service across realistic query shapes.
 *
 * Results are real measurements from the configured database. The command
 * never fabricates values and exits non-zero when an explicit p95 target is
 * supplied and missed.
 */
final class BenchmarkSearch extends Command
{
    /**
     * @var string
     */
    protected $signature = 'search:benchmark
        {--queries=2000 : Number of measured queries}
        {--limit=10 : Suggestion limit per query}
        {--warmup=20 : Warm-up queries excluded from results}
        {--export= : Write the generated query corpus to a JSON file}
        {--max-p95=0 : Fail when overall p95 exceeds this many milliseconds (0 disables)}';

    /**
     * @var string
     */
    protected $description = 'Benchmark the uncached autocomplete search and report percentiles';

    /**
     * Execute the command.
     *
     * @param AutocompleteSearch $search Uncached search service.
     * @return int Exit code.
     */
    public function handle(AutocompleteSearch $search): int
    {
        $queryCount = max(1, (int) $this->option('queries'));
        $limit = max(1, min((int) $this->option('limit'), (int) config('search.max_limit')));

        $corpus = $this->buildCorpus($queryCount);

        if ($corpus === []) {
            $this->error('No records found. Seed the dataset first.');

            return self::FAILURE;
        }

        if ($this->option('export')) {
            $this->exportCorpus($corpus, (string) $this->option('export'));
        }

        // Warm caches and PostgreSQL buffers before measuring.
        for ($i = 0; $i < max(0, (int) $this->option('warmup')); $i++) {
            $search->search($corpus[$i % count($corpus)]['query'], $limit);
        }

        $timings = [];
        $overall = [];
        $timeouts = [];
        $progress = $this->output->createProgressBar(count($corpus));
        $progress->start();

        foreach ($corpus as $entry) {
            $startedAt = hrtime(true);

            try {
                $result = $search->search($entry['query'], $limit);
                $this->assertResult($result);
            } catch (QueryException) {
                // A statement timeout is a real latency sample, not a reason to
                // abort the run: record it so the percentiles stay honest.
                $timeouts[] = $entry['query'];
            } finally {
                $elapsed = (hrtime(true) - $startedAt) / 1_000_000;
            }

            $timings[$entry['type']][] = $elapsed;
            $overall[] = $elapsed;
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        $rows = [];

        foreach ($timings as $type => $values) {
            $rows[] = $this->summarize($type, $values);
        }

        $rows[] = $this->summarize('ALL', $overall);
        $this->table(['Query type', 'Count', 'p50 ms', 'p95 ms', 'p99 ms', 'Max ms'], $rows);

        if ($timeouts !== []) {
            $this->warn(sprintf(
                '%d of %d queries failed (most likely the %s statement timeout). Their elapsed time is included above.',
                count($timeouts),
                count($overall),
                (string) config('search.read_only_role.statement_timeout', '250ms'),
            ));

            foreach (array_slice($timeouts, 0, 5) as $query) {
                $this->line('  failed query: '.$query);
            }
        }

        $overallSummary = $this->summarize('ALL', $overall, false);
        $p95 = (float) $overallSummary[3];
        $target = (float) $this->option('max-p95');

        $this->line(sprintf(
            'Database: %s | Queries: %d | Cache: disabled | p95: %.2f ms',
            DB::connection(config('search.connection'))->getDatabaseName(),
            count($overall),
            $p95,
        ));

        if ($target > 0 && $p95 > $target) {
            $this->error(sprintf('p95 %.2f ms exceeds the %.2f ms target.', $p95, $target));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Fail loudly if the search returned an unexpected payload.
     *
     * @param SearchResult $result Search result.
     * @return void
     */
    private function assertResult(SearchResult $result): void
    {
        if ($result->tookMs < 0) {
            throw new \RuntimeException('Invalid timing reported by the search service.');
        }
    }

    /**
     * Build a reproducible corpus of realistic query shapes.
     *
     * @param int $count Number of queries required.
     * @return list<array{query:string,type:string}>
     */
    private function buildCorpus(int $count): array
    {
        $samples = $this->sampleContents($count);

        if ($samples === []) {
            return [];
        }

        $types = ['3-char fragment', 'progressive', 'multi-token', 'with house number', 'typo', 'no match'];
        $corpus = [];
        $sampleCount = count($samples);
        $seen = [];

        for ($i = 0; $i < $count; $i++) {
            $content = $samples[$i % $sampleCount];
            $type = $types[$i % count($types)];
            $query = $this->makeQuery($type, $content, $i);

            if ($query === '') {
                continue;
            }

            // Avoid measuring the same string repeatedly. Skipping (rather than
            // retrying the same index) guarantees the loop always terminates;
            // the no-match type is already unique per iteration.
            if (isset($seen[$query]) && $type !== 'no match') {
                continue;
            }

            $seen[$query] = true;
            $corpus[] = ['query' => $query, 'type' => $type];
        }

        return $corpus;
    }

    /**
     * Generate one query for a type.
     *
     * @param string $type Query type label.
     * @param string $content Sample record content.
     * @param int $index Sequence index for deterministic variation.
     * @return string Query text.
     */
    private function makeQuery(string $type, string $content, int $index): string
    {
        $tokens = preg_split('/\s+/', trim($content)) ?: [];

        if (count($tokens) < 3) {
            return '';
        }

        $first = $tokens[1] ?? $tokens[0];
        $second = $tokens[2] ?? $first;

        return match ($type) {
            '3-char fragment' => mb_substr($first, 0, 3),
            'progressive' => mb_substr($first.' '.$second, 0, 8),
            'multi-token' => $second.' '.$first,
            'with house number' => $tokens[0].' '.mb_substr($first, 0, 4),
            'typo' => $this->makeTypo($first).' '.mb_substr($second, 0, 3),
            'no match' => 'qzxv'.$index,
            default => $first,
        };
    }

    /**
     * Introduce a single-character deletion into a word.
     *
     * @param string $word Source word.
     * @return string Misspelled word.
     */
    private function makeTypo(string $word): string
    {
        if (mb_strlen($word) < 5) {
            return $word.'x';
        }

        $middle = (int) floor(mb_strlen($word) / 2);

        return mb_substr($word, 0, $middle).mb_substr($word, $middle + 1);
    }

    /**
     * Sample record contents without a full-table sort.
     *
     * @param int $count Desired sample size.
     * @return list<string>
     */
    private function sampleContents(int $count): array
    {
        // Corpus sampling is setup work, not a measured search. The read-only
        // role's 250 ms statement_timeout makes count(*) and TABLESAMPLE fail
        // on a 10M-row table, so use the trusted CLI connection here.
        $connection = DB::connection();
        $total = (int) $connection->table('records')->count();
        $wanted = max(500, min($total, $count * 2));

        if ($total === 0) {
            return [];
        }

        if ($total > 50000) {
            $rows = $connection->select(
                'SELECT content FROM records TABLESAMPLE SYSTEM (5) LIMIT ?',
                [$wanted]
            );
        } else {
            $rows = $connection->select('SELECT content FROM records LIMIT ?', [$wanted]);
        }

        return array_map(static fn (object $row): string => (string) $row->content, $rows);
    }

    /**
     * Summarize a timing set.
     *
     * @param string $type Label.
     * @param list<float> $values Samples in milliseconds.
     * @param bool $rounded Whether to round strings for display.
     * @return list<int|string|float>
     */
    private function summarize(string $type, array $values, bool $rounded = true): array
    {
        sort($values);
        $count = count($values);

        $percentile = static function (float $p) use ($values, $count): float {
            if ($count === 0) {
                return 0.0;
            }

            $index = (int) max(0, min($count - 1, ceil($p / 100 * $count) - 1));

            return $values[$index];
        };

        $p50 = $percentile(50);
        $p95 = $percentile(95);
        $p99 = $percentile(99);
        $max = $count > 0 ? $values[$count - 1] : 0.0;

        if ($rounded) {
            return [
                $type,
                $count,
                number_format($p50, 2),
                number_format($p95, 2),
                number_format($p99, 2),
                number_format($max, 2),
            ];
        }

        return [$type, $count, $p50, $p95, $p99, $max];
    }

    /**
     * Write the query corpus as JSON for k6 or external tooling.
     *
     * @param list<array{query:string,type:string}> $corpus Query corpus.
     * @param string $path Destination file.
     * @return void
     */
    private function exportCorpus(array $corpus, string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $queries = array_values(array_unique(array_column($corpus, 'query')));
        file_put_contents($path, json_encode($queries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line('Exported '.count($queries)." queries to {$path}.");
    }
}
