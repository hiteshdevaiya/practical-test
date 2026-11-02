<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Usage\SearchCounter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the informational, never-billable search request counter.
 */
final class SearchCounterTest extends TestCase
{
    /**
     * Counter under test.
     */
    private SearchCounter $counter;

    /**
     * Create a counter backed by an in-memory cache.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->counter = new SearchCounter(new Repository(new ArrayStore));
    }

    /**
     * An unseen client starts at zero.
     *
     * @return void
     */
    public function test_missing_counter_reads_as_zero(): void
    {
        $this->assertSame(0, $this->counter->get(1));
    }

    /**
     * Increments accumulate per client and are isolated between clients.
     *
     * @return void
     */
    public function test_increments_are_per_client(): void
    {
        $this->counter->increment(1);
        $this->counter->increment(1);
        $this->counter->increment(2);

        $this->assertSame(2, $this->counter->get(1));
        $this->assertSame(1, $this->counter->get(2));
        $this->assertSame(0, $this->counter->get(3));
    }
}
