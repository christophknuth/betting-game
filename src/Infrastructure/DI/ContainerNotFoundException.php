<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\DI;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

/**
 * PSR-11 NotFoundException
 */
final class ContainerNotFoundException extends Exception implements NotFoundExceptionInterface
{
}
