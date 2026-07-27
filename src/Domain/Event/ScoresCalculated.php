<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class ScoresCalculated extends DomainEvent
{
    public function __construct(
        private string $gameEventId,
        private int $predictionsEvaluated,
        ?string $domainEventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($domainEventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->gameEventId;
    }

    public function aggregateType(): string
    {
        return 'event';
    }

    public function eventType(): string
    {
        return 'scores.calculated';
    }

    public function gameEventId(): string
    {
        return $this->gameEventId;
    }

    public function predictionsEvaluated(): int
    {
        return $this->predictionsEvaluated;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->gameEventId,
            'predictions_evaluated' => $this->predictionsEvaluated,
        ];
    }
}
