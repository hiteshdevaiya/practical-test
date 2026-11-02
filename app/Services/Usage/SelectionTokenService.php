<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Exceptions\InvalidSelectionTokenException;
use App\Exceptions\SelectionTokenForbiddenException;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Issues and verifies stateless HMAC-SHA256 selection tokens.
 *
 * Tokens let the search endpoint stay completely write-free while still
 * proving, at selection time, which client was shown which record for which
 * query, and when. A token can be verified without a database read.
 */
final class SelectionTokenService
{
    /**
     * @param string $secret Configuration value, optionally base64 encoded.
     * @param int $ttl Token lifetime in seconds.
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
    ) {}

    /**
     * Issue a signed token for one suggestion.
     *
     * @param int $clientId API client id.
     * @param int $recordId Suggested record id.
     * @param string $searchId Search request id grouping dropdown suggestions.
     * @param string $query Normalized query.
     * @return string URL-safe signed token.
     */
    public function issue(int $clientId, int $recordId, string $searchId, string $query): string
    {
        $payload = json_encode([
            'cid' => $clientId,
            'rid' => $recordId,
            'sid' => $searchId,
            'jti' => (string) Str::uuid(),
            'q' => mb_substr($query, 0, 100),
            'exp' => now()->timestamp + $this->ttl,
        ], JSON_THROW_ON_ERROR);

        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->normalizedSecret(), true);

        return $encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * Verify a token, its expiry and its client binding.
     *
     * @param string $token Untrusted token from the request body.
     * @param int $clientId Authenticated API client id.
     * @return SelectionClaims Verified claims.
     *
     * @throws InvalidSelectionTokenException When the token is malformed, forged or expired.
     * @throws SelectionTokenForbiddenException When the token belongs to another client.
     */
    public function verify(string $token, int $clientId): SelectionClaims
    {
        if ($token === '' || substr_count($token, '.') !== 1) {
            throw InvalidSelectionTokenException::because('Malformed token.');
        }

        [$encodedPayload, $encodedSignature] = explode('.', $token, 2);

        if ($encodedPayload === '' || $encodedSignature === '') {
            throw InvalidSelectionTokenException::because('Malformed token.');
        }

        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedPayload, $this->normalizedSecret(), true)
        );

        if (! hash_equals($expected, $encodedSignature)) {
            throw InvalidSelectionTokenException::because('Invalid signature.');
        }

        $json = $this->base64UrlDecode($encodedPayload);

        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidSelectionTokenException::because('Invalid payload.');
        }

        if (! is_array($payload)) {
            throw InvalidSelectionTokenException::because('Invalid payload.');
        }

        $claims = SelectionClaims::fromArray($payload);

        if ($claims->expiresAt < now()->timestamp) {
            throw InvalidSelectionTokenException::because('Token expired.');
        }

        if ($claims->clientId !== $clientId) {
            throw new SelectionTokenForbiddenException('Token was issued to another client.');
        }

        return $claims;
    }

    /**
     * Decode the configured secret, supporting Laravel's "base64:" prefix.
     *
     * @return string Raw secret bytes.
     */
    private function normalizedSecret(): string
    {
        if ($this->secret === '') {
            throw new RuntimeException('SELECTION_TOKEN_SECRET is not configured.');
        }

        $secret = $this->secret;

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('SELECTION_TOKEN_SECRET contains invalid base64.');
            }

            $secret = $decoded;
        }

        if (strlen($secret) < 32) {
            throw new RuntimeException('SELECTION_TOKEN_SECRET must contain at least 32 bytes.');
        }

        return $secret;
    }

    /**
     * Encode bytes as unpadded base64url.
     *
     * @param string $value Raw bytes.
     * @return string URL-safe encoding.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decode unpadded base64url.
     *
     * @param string $value URL-safe encoding.
     * @return string Raw bytes.
     *
     * @throws InvalidSelectionTokenException When the value is not valid base64url.
     */
    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw InvalidSelectionTokenException::because('Invalid payload encoding.');
        }

        return $decoded;
    }
}
