<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\QueryNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests query normalization and tokenization rules.
 */
final class QueryNormalizerTest extends TestCase
{
    /**
     * Normalizer under test.
     */
    private QueryNormalizer $normalizer;

    /**
     * Create the normalizer used by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new QueryNormalizer(100, 6, 3);
    }

    /**
     * Lowercases, strips punctuation and collapses whitespace.
     *
     * @return void
     */
    public function test_it_normalizes_case_punctuation_and_whitespace(): void
    {
        $this->assertSame('london road', $this->normalizer->normalize('  LonDON!!  Road%_  '));
    }

    /**
     * Removes SQL LIKE wildcards so user input cannot broaden a match.
     *
     * @return void
     */
    public function test_it_removes_like_wildcards(): void
    {
        $normalized = $this->normalizer->normalize('100%_lon_don');

        $this->assertSame('100 lon don', $normalized);
        $this->assertStringNotContainsString('%', $normalized);
        $this->assertStringNotContainsString('_', $normalized);
    }

    /**
     * Caps the normalized query length.
     *
     * @return void
     */
    public function test_it_caps_query_length(): void
    {
        $this->assertSame(100, mb_strlen($this->normalizer->normalize(str_repeat('a', 150))));
    }

    /**
     * Produces unique tokens capped at the configured maximum.
     *
     * @return void
     */
    public function test_it_returns_unique_capped_tokens(): void
    {
        $tokens = $this->normalizer->tokens('a b a c d e f g h');

        $this->assertSame(['a', 'b', 'c', 'd', 'e', 'f'], $tokens);
    }

    /**
     * Requires at least one token long enough to use the trigram index.
     *
     * @return void
     */
    public function test_it_detects_indexable_tokens(): void
    {
        $this->assertFalse($this->normalizer->hasIndexableToken(['25', '1']));
        $this->assertTrue($this->normalizer->hasIndexableToken(['25', 'lon']));
        $this->assertFalse($this->normalizer->hasIndexableToken([]));
    }

    /**
     * Returns no tokens for an empty query.
     *
     * @return void
     */
    public function test_empty_query_has_no_tokens(): void
    {
        $this->assertSame([], $this->normalizer->tokens(''));
    }
}
