<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\SearchResult;
use App\Services\Search\Suggestion;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the immutable search result value objects.
 */
final class SearchResultTest extends TestCase
{
    /**
     * An empty result carries no suggestions and zero timings.
     *
     * @return void
     */
    public function test_empty_result_has_no_suggestions(): void
    {
        $result = SearchResult::empty('lo');

        $this->assertSame('lo', $result->query);
        $this->assertNull($result->correctedQuery);
        $this->assertSame([], $result->suggestions);
        $this->assertSame(0.0, $result->tookMs);
        $this->assertSame(0.0, $result->dbMs);
        $this->assertFalse($result->cacheHit);
    }

    /**
     * A cache hit copy keeps the rows but reports cache timings only.
     *
     * @return void
     */
    public function test_cache_hit_copy_preserves_rows_and_resets_database_time(): void
    {
        $result = new SearchResult(
            'london',
            'london',
            [new Suggestion(1, '25 London Road Leeds', 'exact', 0.9)],
            12.5,
            8.0,
        );

        $cached = $result->asCacheHit(0.4);

        $this->assertTrue($cached->cacheHit);
        $this->assertSame(0.4, $cached->tookMs);
        $this->assertSame(0.0, $cached->dbMs);
        $this->assertSame($result->suggestions, $cached->suggestions);
        $this->assertSame('london', $cached->query);
        $this->assertSame('london', $cached->correctedQuery);
    }

    /**
     * Suggestions serialize without their internal similarity score.
     *
     * @return void
     */
    public function test_suggestions_serialize_for_transport(): void
    {
        $result = new SearchResult('road', null, [
            new Suggestion(7, '25 London Road Leeds', 'exact', 0.75),
            new Suggestion(9, '91 London Road Manchester', 'corrected'),
        ], 3.0, 2.0);

        $this->assertSame([
            ['id' => 7, 'content' => '25 London Road Leeds', 'match' => 'exact'],
            ['id' => 9, 'content' => '91 London Road Manchester', 'match' => 'corrected'],
        ], $result->suggestionArrays());
    }
}
