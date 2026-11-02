<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\TypoCorrector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies vocabulary-based typo correction in isolation.
 */
final class TypoCorrectorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed a vocabulary and return the corrector.
     *
     * @return TypoCorrector
     */
    private function corrector(): TypoCorrector
    {
        $this->seedRecords([
            '25 London Road Leeds',
            '91 London Road Manchester',
            '118 Church Lane Bristol',
        ]);
        $this->rebuildVocabulary();

        return app(TypoCorrector::class);
    }

    /**
     * Unknown words are replaced by the closest vocabulary word.
     *
     * @return void
     */
    public function test_unknown_token_is_corrected(): void
    {
        $corrected = $this->corrector()->correct(['londn', 'road']);

        $this->assertSame(['london', 'road'], $corrected);
    }

    /**
     * A fully known query is left untouched.
     *
     * @return void
     */
    public function test_known_tokens_are_not_changed(): void
    {
        $this->assertNull($this->corrector()->correct(['london', 'road']));
    }

    /**
     * Tokens shorter than the trigram minimum are never corrected.
     *
     * @return void
     */
    public function test_short_tokens_are_ignored(): void
    {
        $this->assertNull($this->corrector()->correct(['25', 'lon']));
    }

    /**
     * A token with no sufficiently similar vocabulary word is left alone.
     *
     * @return void
     */
    public function test_distant_token_is_not_corrected(): void
    {
        $this->assertNull($this->corrector()->correct(['qzxv']));
    }

    /**
     * The vocabulary is a small table, not the records table.
     *
     * @return void
     */
    public function test_vocabulary_is_built_from_record_words(): void
    {
        $this->corrector();

        $words = DB::table('search_vocabulary')->orderBy('word')->pluck('word')->all();

        $this->assertContains('london', $words);
        $this->assertContains('road', $words);
        $this->assertNotContains('25', $words, 'Numeric house numbers must not enter the vocabulary.');
    }
}
