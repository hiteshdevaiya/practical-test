<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when a valid token was issued to a different API client.
 *
 * Rendered as HTTP 403 by the API layer.
 */
class SelectionTokenForbiddenException extends InvalidSelectionTokenException {}
