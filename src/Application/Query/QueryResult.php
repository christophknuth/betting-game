<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

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
