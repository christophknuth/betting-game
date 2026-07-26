<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class ResultUpdated extends DomainEvent
{
    public function __construct(
        private string $resultId,
        private int $gameEventId,
        private array $resultData,
        private ?string $reason = null,
        ?string $domainEventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($domainEventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->resultId;
    }

    public function aggregateType(): string
    {
        return 'result';
    }

    public function eventType(): string
    {
        return 'result.updated';
    }

    public function gameEventId(): int
    {
        return $this->gameEventId;
    }

    public function resultData(): array
    {
        return $this->resultData;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function toArray(): array
    {
        return [
            'result_id' => $this->resultId,
            'event_id' => $this->gameEventId,
            'result_data' => $this->resultData,
            'reason' => $this->reason,
        ];
    }
}
