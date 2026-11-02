<?php

declare(strict_types=1);

/**
 * Captures EXPLAIN (ANALYZE, BUFFERS) evidence for the autocomplete shapes.
 *
 * Each query runs through the real App\Services\Search\AutocompleteSearch on
 * the configured read-only search connection (so the role's statement timeout
 * and planner settings apply). The SQL the service actually emits is captured
 * with a query listener and then explained, so this evidence cannot drift from
 * the code. Output is written to benchmarks/explain-10m.txt.
 *
 * Usage:
 *   php benchmarks/explain.php [--out=benchmarks/explain-10m.txt]
 */

use App\Services\Search\AutocompleteSearch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = __DIR__.'/explain-10m.txt';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--out=')) {
        $out = substr($argument, 6);
    }
}

$connection = $app['db']->connection(config('search.connection'));

$cases = [
    'lon' => '3-character fragment',
    'london roa' => 'two partial words (adjacent pair)',
    '104 hawt' => 'house number followed by a partial word',
    'church lane bri' => 'three tokens (pair plus filter)',
    'leeds greenwich' => 'tokens in reverse order and not adjacent',
    'londn road' => 'typo corrected through the vocabulary',
    'enwic oad' => 'fragments from the middle of words (trigram fallback)',
    'qzxv999' => 'no match',
];

$captured = [];
$connection->listen(static function (QueryExecuted $query) use (&$captured): void {
    $captured[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
});

// Counting 10M rows exceeds the read-only role's statement timeout, so use the
// trusted connection for this one setup query.
$report = ['Rows in records: '.number_format((int) $app['db']->connection()->selectOne('SELECT count(*) AS c FROM records')->c), ''];

$service = $app->make(AutocompleteSearch::class);

foreach ($cases as $query => $description) {
    // Run once so caches are warm, then again to capture the plans.
    $service->search($query, 10);
    $captured = [];
    $result = $service->search($query, 10);
    $statements = $captured;

    $report[] = str_repeat('=', 78);
    $report[] = sprintf('%s  (%s)', $query, $description);
    $report[] = sprintf(
        'rows=%d corrected=%s took_ms=%.2f db_ms=%.2f statements=%d',
        count($result->suggestions),
        $result->correctedQuery ?? 'none',
        $result->tookMs,
        $result->dbMs,
        count($statements),
    );

    foreach ($statements as $index => $statement) {
        $report[] = '';
        $report[] = sprintf('-- statement %d (%.2f ms): %s', $index + 1, $statement['ms'], preg_replace('/\s+/', ' ', substr(trim($statement['sql']), 0, 110)));

        if (! preg_match('/^\s*(WITH|SELECT)/i', $statement['sql'])) {
            continue;
        }

        try {
            foreach ($connection->select('EXPLAIN (ANALYZE, BUFFERS) '.$statement['sql'], $statement['bindings']) as $row) {
                $report[] = '   '.current((array) $row);
            }
        } catch (Throwable $exception) {
            $report[] = '   EXPLAIN failed: '.strtok($exception->getMessage(), "\n");
        }
    }

    $report[] = '';
}

file_put_contents($out, implode(PHP_EOL, $report).PHP_EOL);

echo 'Wrote '.$out.PHP_EOL;
