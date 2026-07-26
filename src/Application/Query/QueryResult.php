<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class QueryResult
{
    public function __construct(
        private array $data
    ) {
    }

    public function data(): array
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
