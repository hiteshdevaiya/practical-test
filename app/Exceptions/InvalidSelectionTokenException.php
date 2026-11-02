<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a selection token is malformed, tampered with or expired.
 *
 * Rendered as HTTP 422 by the API layer.
 */
class InvalidSelectionTokenException extends RuntimeException
{
    /**
     * Create an exception for a rejected token.
     *
     * @param string $reason Human readable, non-sensitive reason.
     * @return self
     */
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
