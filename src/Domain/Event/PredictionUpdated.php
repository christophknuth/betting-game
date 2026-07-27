<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class PredictionUpdated extends DomainEvent
{
    /** @param array<string, mixed> $predictionData */
    public function __construct(
        private string $predictionId,
        private array $predictionData,
        private int $version,
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
        return 'prediction.updated';
    }

    /** @return array<string, mixed> */
    public function predictionData(): array
    {
        return $this->predictionData;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'prediction_id' => $this->predictionId,
            'prediction_data' => $this->predictionData,
            'version' => $this->version,
        ];
    }
}
