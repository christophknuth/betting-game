<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

interface PredictionReadModelRepositoryInterface
{
    /**
     * @return PredictionReadModel[]
     */
    public function findByParticipant(
        int $participantId,
        ?int $bettingGameId = null,
        ?int $eventId = null,
        ?string $status = null
    ): array;

    public function findById(string $predictionId): ?PredictionReadModel;
}
