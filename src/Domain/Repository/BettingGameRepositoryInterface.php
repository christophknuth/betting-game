<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\BettingGame;

interface BettingGameRepositoryInterface
{
    public function save(BettingGame $game): void;

    public function findById(int $id): ?BettingGame;

    public function nextIdentity(): int;
}
