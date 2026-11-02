<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\CachedAutocompleteSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Precomputes results for the most common short fragments.
 *
 * Broad three- and four-character prefixes are the slowest autocomplete
 * queries. Warming them from the vocabulary makes first keystrokes fast
 * without changing the search implementation.
 */
final class WarmSearchCache extends Command
{
    /**
     * @var string
     */
    protected $signature = 'search:warm-cache
        {--words=100 : Number of frequent vocabulary words to warm}
        {--limit=10 : Suggestion limit used while warming}';

    /**
     * @var string
     */
    protected $description = 'Warm the result cache for frequent short query fragments';

    /**
     * Execute the command.
     *
     * @param CachedAutocompleteSearch $search Cached search service.
     * @return int Exit code.
     */
    public function handle(CachedAutocompleteSearch $search): int
    {
        if (! config('search.cache.enabled')) {
            $this->warn('SEARCH_CACHE_ENABLED is false; nothing was warmed.');

            return self::SUCCESS;
        }

        $wordCount = max(1, (int) $this->option('words'));
        $limit = max(1, (int) $this->option('limit'));

        $words = DB::table('search_vocabulary')
            ->whereRaw('length(word) >= 4')
            ->orderByDesc('row_count')
            ->limit($wordCount)
            ->pluck('word');

        if ($words->isEmpty()) {
            $this->warn('The vocabulary is empty. Seed records first.');

            return self::FAILURE;
        }

        $fragments = [];

        foreach ($words as $word) {
            $fragments[] = mb_substr($word, 0, 3);
            $fragments[] = mb_substr($word, 0, 4);
            $fragments[] = $word;
        }

        $fragments = array_values(array_unique($fragments));
        $progress = $this->output->createProgressBar(count($fragments));
        $progress->start();

        foreach ($fragments as $fragment) {
            $search->search($fragment, $limit);
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);
        $this->info('Warmed '.count($fragments).' query fragments.');

        return self::SUCCESS;
    }
}
