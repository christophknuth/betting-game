<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

use RuntimeException;

/**
 * Thrown when a request body field is missing or has the wrong type.
 * Controllers translate this into a 400 response.
 */
final class InvalidInputException extends RuntimeException
{
}
