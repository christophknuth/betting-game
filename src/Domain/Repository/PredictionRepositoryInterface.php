<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Prediction;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\EventId;

interface PredictionRepositoryInterface
{
    public function save(Prediction $prediction): void;

    public function findById(string $id): ?Prediction;

    /**
     * @return Prediction[]
     */
    public function findByParticipant(ParticipantId $participantId): array;

    /**
     * @return Prediction[]
     */
    public function findByEvent(EventId $eventId): array;

    public function exists(ParticipantId $participantId, EventId $eventId): bool;

    public function nextIdentity(): string;
}
