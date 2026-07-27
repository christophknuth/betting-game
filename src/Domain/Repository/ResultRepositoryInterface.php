<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Result;

interface ResultRepositoryInterface
{
    public function save(Result $result): void;

    public function findById(int $id): ?Result;

    public function findByEventId(int $eventId): ?Result;

    public function nextIdentity(): int;
}
