<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\PrefixIndexBuilder;
use Illuminate\Console\Command;

/**
 * Rebuilds the B-tree word index from the current records table.
 */
final class BuildSearchPrefixIndex extends Command
{
    /**
     * @var string
     */
    protected $signature = 'search:build-prefix-index';

    /**
     * @var string
     */
    protected $description = 'Rebuild the B-tree word index used by autocomplete';

    /**
     * Execute the command.
     *
     * @param PrefixIndexBuilder $builder Index builder.
     * @return int Exit code.
     */
    public function handle(PrefixIndexBuilder $builder): int
    {
        $startedAt = microtime(true);

        $this->info('Rebuilding word index...');
        $result = $builder->rebuild();

        $this->table(['Table', 'Rows'], [
            ['record_words', number_format($result['words'])],
            ['record_word_pairs', number_format($result['pairs'])],
        ]);

        $this->info(sprintf('Done in %.1f s.', microtime(true) - $startedAt));

        return self::SUCCESS;
    }
}
