<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\InvalidSelectionTokenException;
use App\Exceptions\SelectionTokenForbiddenException;
use App\Services\Usage\SelectionTokenService;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests signing, verification and rejection of selection tokens.
 */
final class SelectionTokenServiceTest extends TestCase
{
    /**
     * Secret long enough to satisfy the minimum key length.
     */
    private const SECRET = '0123456789abcdef0123456789abcdef';

    /**
     * Issues a token and verifies every claim round-trips.
     *
     * @return void
     */
    public function test_it_issues_and_verifies_a_token(): void
    {
        $service = new SelectionTokenService(self::SECRET, 600);
        $searchId = (string) Str::uuid();
        $token = $service->issue(7, 482193, $searchId, 'london road');

        $claims = $service->verify($token, 7);

        $this->assertSame(7, $claims->clientId);
        $this->assertSame(482193, $claims->recordId);
        $this->assertSame($searchId, $claims->searchId);
        $this->assertSame('london road', $claims->query);
        $this->assertNotEmpty($claims->selectionId);
        $this->assertGreaterThan(time(), $claims->expiresAt);
    }

    /**
     * Rejects a token whose signature was modified.
     *
     * @return void
     */
    public function test_it_rejects_a_tampered_signature(): void
    {
        $service = new SelectionTokenService(self::SECRET, 600);
        $token = $service->issue(1, 1, (string) Str::uuid(), 'query');
        [$payload] = explode('.', $token, 2);

        $this->expectException(InvalidSelectionTokenException::class);
        $service->verify($payload.'.deadbeef', 1);
    }

    /**
     * Rejects a malformed token without a signature separator.
     *
     * @return void
     */
    public function test_it_rejects_malformed_tokens(): void
    {
        $service = new SelectionTokenService(self::SECRET, 600);

        $this->expectException(InvalidSelectionTokenException::class);
        $service->verify('not-a-token', 1);
    }

    /**
     * Rejects an expired token.
     *
     * @return void
     */
    public function test_it_rejects_expired_tokens(): void
    {
        $service = new SelectionTokenService(self::SECRET, -10);
        $token = $service->issue(1, 1, (string) Str::uuid(), 'query');

        $this->expectException(InvalidSelectionTokenException::class);
        $service->verify($token, 1);
    }

    /**
     * Rejects a token issued to a different client.
     *
     * @return void
     */
    public function test_it_rejects_tokens_from_another_client(): void
    {
        $service = new SelectionTokenService(self::SECRET, 600);
        $token = $service->issue(1, 1, (string) Str::uuid(), 'query');

        $this->expectException(SelectionTokenForbiddenException::class);
        $service->verify($token, 2);
    }

    /**
     * Rejects missing or too-short secrets instead of signing weakly.
     *
     * @return void
     */
    public function test_it_requires_a_strong_secret(): void
    {
        $service = new SelectionTokenService('short', 600);

        $this->expectException(RuntimeException::class);
        $service->issue(1, 1, (string) Str::uuid(), 'query');
    }
}
