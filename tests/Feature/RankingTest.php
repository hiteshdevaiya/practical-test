<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\AutocompleteSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies deterministic ranking, merge priority and de-duplication.
 */
final class RankingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Search service under test.
     */
    private AutocompleteSearch $search;

    /**
     * Seed a small ranking fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRecords([
            'London Road',
            'London Road Leeds',
            'London Road Manchester Didsbury',
            'East London Road',
            '25 London Road Leeds',
        ]);

        $this->rebuildVocabulary();
        $this->search = app(AutocompleteSearch::class);
    }

    /**
     * Rows that start with the query rank before rows that merely contain it,
     * and ties fall back to shorter content.
     *
     * @return void
     */
    public function test_prefix_matches_rank_first_then_shorter_content(): void
    {
        $result = $this->search->search('london road', 10);
        $contents = array_map(
            static fn ($suggestion): string => $suggestion->content,
            $result->suggestions
        );

        $this->assertSame([
            'London Road',
            'London Road Leeds',
            'London Road Manchester Didsbury',
            'East London Road',
            '25 London Road Leeds',
        ], $contents);
    }

    /**
     * Repeated identical requests return the same order.
     *
     * @return void
     */
    public function test_ranking_is_stable_across_requests(): void
    {
        $first = array_map(
            static fn ($suggestion): int => $suggestion->id,
            $this->search->search('road', 10)->suggestions
        );
        $second = array_map(
            static fn ($suggestion): int => $suggestion->id,
            $this->search->search('road', 10)->suggestions
        );

        $this->assertSame($first, $second);
    }

    /**
     * A stale vocabulary must not hide rows that match the exact query, and a
     * row returned by both passes appears once with the exact match label.
     *
     * @return void
     */
    public function test_exact_matches_precede_corrected_matches_without_duplicates(): void
    {
        // Inserted after the vocabulary rebuild, so "londn" is unknown to the
        // corrector even though a record contains it (stale-vocabulary case).
        DB::table('records')->insert([
            ['content' => '99 Londn Road Bristol'],
            ['content' => 'Londn London Road Bath'],
        ]);

        $result = $this->search->search('londn road', 10);

        $this->assertSame('london road', $result->correctedQuery);

        $map = [];

        foreach ($result->suggestions as $suggestion) {
            $map[$suggestion->content] = $suggestion->match;
        }

        $this->assertSame('exact', $map['99 Londn Road Bristol'] ?? null);
        $this->assertSame('exact', $map['Londn London Road Bath'] ?? null);
        $this->assertSame('corrected', $map['London Road'] ?? null);

        $ids = array_map(static fn ($suggestion): int => $suggestion->id, $result->suggestions);
        $this->assertSame($ids, array_values(array_unique($ids)), 'Suggestions must not repeat a record id.');

        // The row matched by both passes is labelled exact, never corrected.
        $matches = array_count_values(array_map(
            static fn ($suggestion): string => $suggestion->content,
            $result->suggestions
        ));
        $this->assertSame(1, $matches['Londn London Road Bath']);
    }
}
