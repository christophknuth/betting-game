<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class PredictionSubmitted extends DomainEvent
{
    /** @param array<string, mixed> $predictionData */
    public function __construct(
        private string $predictionId,
        private int $participantId,
        private int $gameEventId,
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

    /**
     * The game event being predicted - not to be confused with DomainEvent::eventId(),
     * which identifies this event store entry.
     */
    public function gameEventId(): int
    {
        return $this->gameEventId;
    }

    /** @return array<string, mixed> */
    public function predictionData(): array
    {
        return $this->predictionData;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'prediction_id' => $this->predictionId,
            'participant_id' => $this->participantId,
            'event_id' => $this->gameEventId,
            'prediction_data' => $this->predictionData,
        ];
    }
}
