<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\DI;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * PSR-11 ContainerException
 */
final class ContainerException extends Exception implements ContainerExceptionInterface
{
}
