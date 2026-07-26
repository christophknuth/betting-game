<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class PredictionEvaluated extends DomainEvent
{
    public function __construct(
        private string $predictionId,
        private int $pointsEarned,
        private ?float $prizeAmount,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->predictionId;
    }

    public function aggregateType(): string
    {
        return 'prediction';
    }

    public function eventType(): string
    {
        return 'prediction.evaluated';
    }

    public function pointsEarned(): int
    {
        return $this->pointsEarned;
    }

    public function prizeAmount(): ?float
    {
        return $this->prizeAmount;
    }

    public function toArray(): array
    {
        return [
            'prediction_id' => $this->predictionId,
            'points_earned' => $this->pointsEarned,
            'prize_amount' => $this->prizeAmount,
        ];
    }
}
