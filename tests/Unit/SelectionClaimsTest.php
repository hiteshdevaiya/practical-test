<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\InvalidSelectionTokenException;
use App\Services\Usage\SelectionClaims;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * Verifies defensive parsing of selection token claims.
 */
final class SelectionClaimsTest extends TestCase
{
    /**
     * Build a valid payload with optional overrides.
     *
     * @param array<string, mixed> $overrides Claims to replace.
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'cid' => 3,
            'rid' => 42,
            'sid' => (string) Str::uuid(),
            'jti' => (string) Str::uuid(),
            'q' => 'london road',
            'exp' => time() + 600,
        ], $overrides);
    }

    /**
     * A complete payload becomes an immutable claims object.
     *
     * @return void
     */
    public function test_valid_payload_is_accepted(): void
    {
        $payload = $this->payload();
        $claims = SelectionClaims::fromArray($payload);

        $this->assertSame(3, $claims->clientId);
        $this->assertSame(42, $claims->recordId);
        $this->assertSame($payload['sid'], $claims->searchId);
        $this->assertSame($payload['jti'], $claims->selectionId);
        $this->assertSame('london road', $claims->query);
        $this->assertSame($payload['exp'], $claims->expiresAt);
    }

    /**
     * Every required claim must be present.
     *
     * @return void
     */
    public function test_missing_claim_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['jti']);

        $this->expectException(InvalidSelectionTokenException::class);
        SelectionClaims::fromArray($payload);
    }

    /**
     * Numeric claims must be real integers, not strings.
     *
     * @return void
     */
    public function test_non_integer_identifier_is_rejected(): void
    {
        $this->expectException(InvalidSelectionTokenException::class);
        SelectionClaims::fromArray($this->payload(['cid' => '3']));
    }

    /**
     * Search and selection identifiers must be UUIDs.
     *
     * @return void
     */
    public function test_non_uuid_identifiers_are_rejected(): void
    {
        $this->expectException(InvalidSelectionTokenException::class);
        SelectionClaims::fromArray($this->payload(['sid' => 'search-id']));
    }

    /**
     * The audit query is bounded so a token cannot store arbitrary text.
     *
     * @return void
     */
    public function test_overlong_query_is_rejected(): void
    {
        $this->expectException(InvalidSelectionTokenException::class);
        SelectionClaims::fromArray($this->payload(['q' => str_repeat('a', 101)]));
    }

    /**
     * Identifiers must be positive.
     *
     * @return void
     */
    public function test_zero_identifiers_are_rejected(): void
    {
        $this->expectException(InvalidSelectionTokenException::class);
        SelectionClaims::fromArray($this->payload(['rid' => 0]));
    }
}
