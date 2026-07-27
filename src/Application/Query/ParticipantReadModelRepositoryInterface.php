<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

interface ParticipantReadModelRepositoryInterface
{
    /**
     * @return ParticipantReadModel[]
     */
    public function findAll(?string $status = null, ?int $bettingGameId = null): array;

    /**
     * @return ParticipantReadModel[]
     */
    public function findPendingByGame(int $bettingGameId): array;
}
