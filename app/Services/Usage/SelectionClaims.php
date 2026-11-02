<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Exceptions\InvalidSelectionTokenException;
use Illuminate\Support\Str;

/**
 * Validated claims embedded in a signed selection token.
 */
final readonly class SelectionClaims
{
    /**
     * @param int $clientId API client the token was issued to.
     * @param int $recordId Suggested record id.
     * @param string $searchId Groups suggestions of one dropdown.
     * @param string $selectionId Unique nonce; stored as selection_id.
     * @param string $query Normalized query for the audit ledger.
     * @param int $expiresAt Unix expiry timestamp.
     */
    public function __construct(
        public int $clientId,
        public int $recordId,
        public string $searchId,
        public string $selectionId,
        public string $query,
        public int $expiresAt,
    ) {}

    /**
     * Build claims from a decoded token payload.
     *
     * @param array<string, mixed> $payload Decoded JSON payload.
     * @return self
     *
     * @throws InvalidSelectionTokenException When a required claim is missing or invalid.
     */
    public static function fromArray(array $payload): self
    {
        foreach (['cid', 'rid', 'sid', 'jti', 'q', 'exp'] as $claim) {
            if (! array_key_exists($claim, $payload)) {
                throw InvalidSelectionTokenException::because("Missing claim: {$claim}");
            }
        }

        if (! is_int($payload['cid']) || ! is_int($payload['rid']) || ! is_int($payload['exp'])) {
            throw InvalidSelectionTokenException::because('Invalid numeric claims.');
        }

        if (! is_string($payload['sid']) || ! is_string($payload['jti']) || ! is_string($payload['q'])) {
            throw InvalidSelectionTokenException::because('Invalid string claims.');
        }

        if (! Str::isUuid($payload['sid']) || ! Str::isUuid($payload['jti'])) {
            throw InvalidSelectionTokenException::because('Invalid identifier claims.');
        }

        if (mb_strlen($payload['q']) > 100) {
            throw InvalidSelectionTokenException::because('Query claim is too long.');
        }

        if ($payload['cid'] < 1 || $payload['rid'] < 1) {
            throw InvalidSelectionTokenException::because('Invalid claim identifiers.');
        }

        return new self(
            $payload['cid'],
            $payload['rid'],
            $payload['sid'],
            $payload['jti'],
            $payload['q'],
            $payload['exp'],
        );
    }
}
