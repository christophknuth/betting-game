<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

interface BettingGameReadModelRepositoryInterface
{
    /**
     * @return BettingGameReadModel[]
     */
    public function findAll(?string $status = null, ?int $gameTypeId = null): array;

    public function findById(int $bettingGameId): ?BettingGameReadModel;
}
