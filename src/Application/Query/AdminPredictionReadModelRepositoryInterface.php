<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

interface AdminPredictionReadModelRepositoryInterface
{
    /**
     * @return PredictionReadModel[]
     */
    public function findAll(
        ?int $bettingGameId = null,
        ?int $eventId = null,
        ?int $participantId = null,
        int $page = 1,
        int $pageSize = 50
    ): array;

    public function countAll(
        ?int $bettingGameId = null,
        ?int $eventId = null,
        ?int $participantId = null
    ): int;
}
