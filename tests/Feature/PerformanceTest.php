<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\AutocompleteSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Measures the search service on a local fixture.
 *
 * This is a performance smoke test, not a substitute for the real 10M-row
 * benchmark in docs/benchmark-results.md. It guards against pathological
 * regressions (for example, losing the trigram index or scanning every row).
 */
#[Group('performance')]
final class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed a few thousand varied records and measure uncached latency.
     *
     * @return void
     */
    public function test_uncached_search_stays_within_the_local_budget(): void
    {
        $this->seedFixture(3000);
        $this->rebuildVocabulary();

        $queries = [
            'lon', 'lond', 'london', 'london r', 'london roa',
            'roa lond', '25 london', 'londn road', 'manchestr', 'qzxv',
        ];

        $search = app(AutocompleteSearch::class);

        // Warm the connection and PostgreSQL buffers.
        foreach ($queries as $query) {
            $search->search($query, 10);
        }

        $timings = [];
        $nonEmpty = 0;

        for ($i = 0; $i < 10; $i++) {
            foreach ($queries as $query) {
                $startedAt = hrtime(true);
                $result = $search->search($query, 10);
                $timings[] = (hrtime(true) - $startedAt) / 1_000_000;

                if ($result->suggestions !== []) {
                    $nonEmpty++;
                }
            }
        }

        $this->assertGreaterThan(0, $nonEmpty, 'The benchmark corpus returned no suggestions.');
        $this->assertLessThanOrEqual(50.0, $this->p95($timings), 'Local p95 exceeded the 50 ms budget.');
        $this->assertLessThanOrEqual(20, count(app(AutocompleteSearch::class)->search('london', 999)->suggestions));
    }

    /**
     * Insert synthetic records in bounded chunks.
     *
     * @param int $count Number of rows.
     * @return void
     */
    private function seedFixture(int $count): void
    {
        $names = ['london', 'church', 'station', 'victoria', 'manchester', 'clifton', 'bristol', 'leeds'];
        $types = ['road', 'street', 'lane', 'avenue', 'close'];
        $towns = ['leeds', 'bristol', 'york', 'bath', 'reading', 'oxford'];
        $chunk = [];
        $connection = DB::connection('pgsql');

        for ($i = 0; $i < $count; $i++) {
            $chunk[] = [
                'content' => sprintf(
                    '%d %s %s %s',
                    1 + ($i % 300),
                    $names[$i % count($names)],
                    $types[intdiv($i, 3) % count($types)],
                    $towns[intdiv($i, 5) % count($towns)],
                ),
            ];

            if (count($chunk) >= 500) {
                $connection->table('records')->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $connection->table('records')->insert($chunk);
        }
    }

    /**
     * Compute the nearest-rank p95 of a timing sample.
     *
     * @param list<float> $values Milliseconds.
     * @return float
     */
    private function p95(array $values): float
    {
        sort($values);
        $count = count($values);
        $index = (int) max(0, min($count - 1, (int) ceil(0.95 * $count) - 1));

        return $values[$index];
    }
}
