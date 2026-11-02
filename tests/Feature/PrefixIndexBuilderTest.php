<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\AutocompleteSearch;
use App\Services\Search\PrefixIndexBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the derived B-tree word tables and the command that rebuilds them.
 */
final class PrefixIndexBuilderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rebuild reports and stores one row per distinct word and adjacent pair.
     *
     * @return void
     */
    public function test_rebuild_stores_words_and_adjacent_pairs(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds', '118 Church Lane Bristol']);

        $result = app(PrefixIndexBuilder::class)->rebuild();

        $this->assertSame(['words' => 8, 'pairs' => 6], $result);
        $this->assertSame(8, DB::table('record_words')->count());
        $this->assertSame(6, DB::table('record_word_pairs')->count());
    }

    /**
     * Pairs join neighbouring words in order and nothing else.
     *
     * @return void
     */
    public function test_pairs_are_adjacent_and_ordered(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds']);

        $this->assertTrue(
            DB::table('record_word_pairs')->where('w1', 'greenwich')->where('w2', 'road')->exists()
        );
        $this->assertFalse(
            DB::table('record_word_pairs')->where('w1', 'road')->where('w2', 'greenwich')->exists()
        );
        $this->assertFalse(
            DB::table('record_word_pairs')->where('w1', '25')->where('w2', 'road')->exists()
        );
    }

    /**
     * Each table can be rebuilt on its own.
     *
     * @return void
     */
    public function test_words_and_pairs_can_be_rebuilt_separately(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds']);
        DB::statement('TRUNCATE record_words, record_word_pairs');

        $this->assertSame(4, app(PrefixIndexBuilder::class)->rebuildWords());
        $this->assertSame(0, DB::table('record_word_pairs')->count());
        $this->assertSame(3, app(PrefixIndexBuilder::class)->rebuildPairs());
    }

    /**
     * Words are lower-cased and kept whole.
     *
     * @return void
     */
    public function test_words_are_lowercase_and_stored_whole(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds']);

        $this->assertTrue(DB::table('record_words')->where('word', 'greenwich')->exists());
        $this->assertFalse(DB::table('record_words')->where('word', 'Greenwich')->exists());
        $this->assertSame(
            4,
            DB::table('record_words')->where('record_id', DB::table('records')->value('id'))->count()
        );
    }

    /**
     * A word repeated inside one record is stored once for that record.
     *
     * @return void
     */
    public function test_repeated_words_are_not_duplicated_per_record(): void
    {
        $this->seedRecords(['Main Main Street Leeds']);

        $this->assertSame(3, DB::table('record_words')->count());
        $this->assertSame(3, DB::table('record_word_pairs')->count());
    }

    /**
     * Rebuilding twice gives the same result instead of accumulating rows.
     *
     * @return void
     */
    public function test_rebuild_is_idempotent(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds']);

        $first = app(PrefixIndexBuilder::class)->rebuild();
        $second = app(PrefixIndexBuilder::class)->rebuild();

        $this->assertSame($first, $second);
        $this->assertSame($second['words'], DB::table('record_words')->count());
        $this->assertSame($second['pairs'], DB::table('record_word_pairs')->count());
    }

    /**
     * The tables are derived data, so new records appear only after a rebuild.
     *
     * @return void
     */
    public function test_new_records_appear_only_after_a_rebuild(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds']);
        $before = DB::table('record_words')->count();

        DB::table('records')->insert(['content' => '7 Victoria Street Bath']);
        $this->assertSame($before, DB::table('record_words')->count());

        app(PrefixIndexBuilder::class)->rebuild();

        $this->assertTrue(DB::table('record_words')->where('word', 'victoria')->exists());
    }

    /**
     * Autovacuum is switched off during the load and restored afterwards.
     *
     * @return void
     */
    public function test_autovacuum_is_re_enabled_after_a_rebuild(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds']);

        foreach (['record_words', 'record_word_pairs'] as $table) {
            $options = DB::selectOne('SELECT reloptions FROM pg_class WHERE relname = ?', [$table])->reloptions;

            $this->assertStringContainsString('autovacuum_enabled=true', (string) $options);
        }
    }

    /**
     * Search still returns rows when the derived tables are empty.
     *
     * @return void
     */
    public function test_search_falls_back_when_the_word_tables_are_empty(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds', '91 Greenwich Road Manchester']);
        $this->rebuildVocabulary();
        DB::statement('TRUNCATE record_words, record_word_pairs');

        $result = app(AutocompleteSearch::class)->search('greenwich road', 10);

        $this->assertCount(2, $result->suggestions);
    }

    /**
     * Neighbouring tokens are answered by the pair index, including a house
     * number followed by a partial word.
     *
     * @return void
     */
    public function test_adjacent_tokens_match_through_the_pair_index(): void
    {
        $this->seedRecords([
            '104 Hawthorn Road Leeds',
            '104 Birch Road Leeds',
            '105 Hawthorn Road Leeds',
        ]);

        $result = app(AutocompleteSearch::class)->search('104 hawt', 10);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('104 Hawthorn Road Leeds', $result->suggestions[0]->content);
    }

    /**
     * Neighbouring tokens match in either typed order.
     *
     * @return void
     */
    public function test_adjacent_tokens_match_in_reverse_order(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds', '118 Church Lane Bristol']);

        $result = app(AutocompleteSearch::class)->search('road greenwich', 10);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('25 Greenwich Road Leeds', $result->suggestions[0]->content);
    }

    /**
     * A pair broader than the candidate cap still finds the rare third token.
     *
     * "church lane" matches far more records than the candidate window holds,
     * so the third token has to be proven before the cap applies.
     *
     * @return void
     */
    public function test_third_token_is_applied_before_the_candidate_cap(): void
    {
        $contents = [];

        for ($number = 1; $number <= 260; $number++) {
            $contents[] = "{$number} Church Lane Leeds";
        }

        $contents[] = '7 Church Lane Bristol';

        $this->seedRecords($contents);

        $result = app(AutocompleteSearch::class)->search('church lane bri', 10);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('7 Church Lane Bristol', $result->suggestions[0]->content);
    }

    /**
     * Tokens that are not neighbours are answered by the word index.
     *
     * @return void
     */
    public function test_non_adjacent_tokens_use_the_word_index_without_scanning(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds', '91 Greenwich Road Manchester']);

        $result = app(AutocompleteSearch::class)->search('leeds 25', 10);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('25 Greenwich Road Leeds', $result->suggestions[0]->content);
    }

    /**
     * Three tokens are answered by a pair plus the remaining token filter.
     *
     * @return void
     */
    public function test_three_tokens_are_filtered_after_the_pair_lookup(): void
    {
        $this->seedRecords([
            '25 Greenwich Road Leeds',
            '91 Greenwich Road Manchester',
            '12 Greenwich Lane Leeds',
        ]);

        $result = app(AutocompleteSearch::class)->search('greenwich road leeds', 10);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('25 Greenwich Road Leeds', $result->suggestions[0]->content);
    }

    /**
     * Partial input is matched as a word prefix through the index.
     *
     * @return void
     */
    public function test_partial_words_match_through_the_prefix_index(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds', '118 Church Lane Bristol']);

        $result = app(AutocompleteSearch::class)->search('gree roa', 10);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('25 Greenwich Road Leeds', $result->suggestions[0]->content);
    }

    /**
     * Tokens match in any order and at any distance inside the record.
     *
     * @return void
     */
    public function test_tokens_match_in_any_order_and_position(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds', '118 Church Lane Bristol']);

        $result = app(AutocompleteSearch::class)->search('leeds greenwich', 10);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('25 Greenwich Road Leeds', $result->suggestions[0]->content);
    }

    /**
     * The rebuild command succeeds and reports the row count.
     *
     * @return void
     */
    public function test_rebuild_command_reports_row_counts(): void
    {
        $this->seedRecords(['25 Greenwich Road Leeds']);

        $this->artisan('search:build-prefix-index')
            ->expectsOutputToContain('Rebuilding word index')
            ->assertExitCode(0);

        $this->assertSame(4, DB::table('record_words')->count());
    }
}
