<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\DI;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use DI\Container as PhpDiContainer;
use Exception;

/**
 * PSR-11 compliant Container adapter
 * Wraps PHP-DI container to provide standard interface
 */
final class PsrContainer implements PsrContainerInterface
{
    public function __construct(
        private PhpDiContainer $container
    ) {
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     * @return mixed Entry.
     * @throws NotFoundExceptionInterface No entry was found for this identifier.
     * @throws ContainerExceptionInterface Error while retrieving the entry.
     */
    public function get(string $id): mixed
    {
        try {
            return $this->container->get($id);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'No entry or class found')) {
                throw new ContainerNotFoundException(
                    "No entry found for identifier: $id",
                    0,
                    $e
                );
            }
            throw new ContainerException(
                "Error while retrieving entry: $id",
                0,
                $e
            );
        }
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for.
     * @return bool
     */
    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * Get the underlying PHP-DI container
     * Useful for advanced features not in PSR-11
     */
    public function getWrappedContainer(): PhpDiContainer
    {
        return $this->container;
    }
}
