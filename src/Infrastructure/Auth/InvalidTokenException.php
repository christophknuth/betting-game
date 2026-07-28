<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

use RuntimeException;

/**
 * The token was read and rejected.
 *
 * The message says precisely why, because that is what an operator needs in the
 * log. It is deliberately not what the client is told - a caller learns only
 * that the token was refused, since the difference between "expired", "wrong
 * issuer" and "bad signature" is a description of our validation rules.
 */
final class InvalidTokenException extends RuntimeException
{
}
