<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/**
 * A read model on its way to the HTTP layer.
 *
 * Query handlers return the shape the API documents - camelCase, nested,
 * already typed - so the controller only has to serialise it.
 */
final class QueryResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private array $data
    ) {
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
