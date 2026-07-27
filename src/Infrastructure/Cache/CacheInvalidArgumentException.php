<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Cache;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * PSR-16 Invalid Argument Exception
 */
final class CacheInvalidArgumentException extends \InvalidArgumentException implements InvalidArgumentException
{
}
