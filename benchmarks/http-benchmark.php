<?php

declare(strict_types=1);

/**
 * Sequential HTTP latency benchmark for the autocomplete endpoint.
 *
 * This complements `php artisan search:benchmark` (which measures the service
 * in-process) by measuring a real HTTP round trip, including framework
 * bootstrap, authentication and database work. It reports the wall-clock
 * request time and, when the application sends one, the Server-Timing parts.
 *
 * The corpus is produced by:
 *   php artisan search:benchmark --queries=2000 --export=benchmarks/queries.json
 *
 * Usage:
 *   php benchmarks/http-benchmark.php --key=<api-key> [--base=http://127.0.0.1:8000]
 *        [--queries=benchmarks/queries.json] [--samples=300] [--warmup=20]
 *        [--limit=10] [--json=benchmarks/http-results.json]
 *
 * Notes:
 * - Requests are issued sequentially. Sequential latency percentiles are the
 *   right signal for a per-request budget; concurrency behaviour needs k6 (see
 *   benchmarks/k6-search.js).
 * - Start the server with the result cache disabled to prove the database path:
 *     SEARCH_CACHE_ENABLED=false php artisan serve
 */

/**
 * Read a `--name=value` option with an optional default.
 *
 * @param array<int, string> $argv Raw arguments.
 * @param string $name Option name.
 * @param string|null $default Fallback value.
 * @return string Option value.
 */
function option(array $argv, string $name, ?string $default = null): string
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--'.$name.'=')) {
            return substr($argument, strlen($name) + 3);
        }
    }

    if ($default === null) {
        fwrite(STDERR, "Missing required option --{$name}=...\n");
        exit(1);
    }

    return $default;
}

/**
 * Compute nearest-rank percentiles for a timing sample.
 *
 * @param list<float> $values Milliseconds.
 * @return array{p50:float,p95:float,p99:float,max:float}
 */
function percentiles(array $values): array
{
    sort($values);
    $count = count($values);

    $at = static function (float $quantile) use ($values, $count): float {
        $index = (int) max(0, min($count - 1, (int) ceil($quantile * $count) - 1));

        return $values[$index];
    };

    return [
        'p50' => $at(0.50),
        'p95' => $at(0.95),
        'p99' => $at(0.99),
        'max' => $values[$count - 1],
    ];
}

/**
 * Parse a Server-Timing header into a name => milliseconds map.
 *
 * @param string|null $header Raw header value.
 * @return array<string, float> Durations in milliseconds.
 */
function serverTiming(?string $header): array
{
    $metrics = [];

    foreach (explode(',', (string) $header) as $segment) {
        if (preg_match('/^\s*([a-z_]+);dur=([0-9.]+)/i', $segment, $matches) === 1) {
            $metrics[strtolower($matches[1])] = (float) $matches[2];
        }
    }

    return $metrics;
}

$base = rtrim(option($argv, 'base', 'http://127.0.0.1:8000'), '/');
$key = option($argv, 'key');
$queryFile = option($argv, 'queries', 'benchmarks/queries.json');
$samples = max(1, (int) option($argv, 'samples', '300'));
$warmup = max(0, (int) option($argv, 'warmup', '20'));
$limit = (int) option($argv, 'limit', '10');
$jsonFile = option($argv, 'json', '');

$queries = json_decode((string) file_get_contents($queryFile), true);

if (! is_array($queries) || $queries === []) {
    fwrite(STDERR, "Could not read a query corpus from {$queryFile}\n");
    exit(1);
}

$total = [];
$app = [];
$db = [];
$statuses = [];
$failures = 0;

$request = static function (string $query) use ($base, $key, $limit): array {
    $url = $base.'/api/v1/search?q='.rawurlencode($query).'&limit='.$limit;
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['X-API-Key: '.$key, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($handle);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $timeMs = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
    curl_close($handle);

    $headers = substr((string) $response, 0, $headerSize);

    return [$status, $timeMs, $headers];
};

// Warm up connections, PHP opcode caches and PostgreSQL shared buffers.
for ($i = 0; $i < $warmup; $i++) {
    $request($queries[$i % count($queries)]);
}

for ($i = 0; $i < $samples; $i++) {
    $query = $queries[$i % count($queries)];
    [$status, $timeMs, $headers] = $request($query);

    $statuses[$status] = ($statuses[$status] ?? 0) + 1;

    if ($status !== 200) {
        $failures++;

        continue;
    }

    $timing = serverTiming(
        preg_match('/^Server-Timing:\s*(.+)$/mi', $headers, $matches) === 1 ? trim($matches[1]) : null
    );

    $total[] = $timeMs;

    if (isset($timing['app'])) {
        $app[] = $timing['app'];
    }

    if (isset($timing['db'])) {
        $db[] = $timing['db'];
    }
}

if ($total === []) {
    fwrite(STDERR, 'No successful requests. Last statuses: '.json_encode($statuses)."\n");
    exit(1);
}

$report = [
    'base_url' => $base,
    'samples' => count($total),
    'failures' => $failures,
    'statuses' => $statuses,
    'total_ms' => percentiles($total),
    'app_ms' => $app === [] ? null : percentiles($app),
    'db_ms' => $db === [] ? null : percentiles($db),
];

printf(
    "HTTP autocomplete latency: %s | samples=%d | failures=%d\n\n",
    $base,
    $report['samples'],
    $failures,
);

printf("%-22s %8s %8s %8s %8s\n", 'Metric', 'p50', 'p95', 'p99', 'max');

foreach (['total_ms' => 'HTTP request', 'app_ms' => 'Server-Timing app', 'db_ms' => 'Server-Timing db'] as $field => $label) {
    $values = $report[$field];

    if ($values === null) {
        printf("%-22s %8s %8s %8s %8s\n", $label, '-', '-', '-', '-');

        continue;
    }

    printf(
        "%-22s %8.2f %8.2f %8.2f %8.2f\n",
        $label,
        $values['p50'],
        $values['p95'],
        $values['p99'],
        $values['max'],
    );
}

if ($jsonFile !== '') {
    file_put_contents($jsonFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    echo "\nJSON written to {$jsonFile}\n";
}
