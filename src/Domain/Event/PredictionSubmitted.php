<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class PredictionSubmitted extends DomainEvent
{
    public function __construct(
        private string $predictionId,
        private int $participantId,
        private int $eventId,
        private array $predictionData,
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
        return 'prediction.submitted';
    }

    public function participantId(): int
    {
        return $this->participantId;
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function predictionData(): array
    {
        return $this->predictionData;
    }

    public function toArray(): array
    {
        return [
            'prediction_id' => $this->predictionId,
            'participant_id' => $this->participantId,
            'event_id' => $this->eventId,
            'prediction_data' => $this->predictionData,
        ];
    }
}
