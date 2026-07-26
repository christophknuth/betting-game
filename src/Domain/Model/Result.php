<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\ResultRecorded;
use BettingGame\Domain\Event\ResultUpdated;
use DateTimeImmutable;

final class Result
{
    private array $recordedEvents = [];

    private function __construct(
        private int $id,
        private int $eventId,
        private array $resultData,
        private ?string $source,
        private DateTimeImmutable $recordedAt,
        private ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public static function record(
        int $id,
        int $eventId,
        array $resultData,
        ?string $source = null
    ): self {
        $result = new self(
            $id,
            $eventId,
            $resultData,
            $source,
            new DateTimeImmutable()
        );

        $result->recordEvent(new ResultRecorded(
            (string) $id,
            $eventId,
            $resultData,
            $source
        ));

        return $result;
    }

    public function update(array $resultData, ?string $reason = null): void
    {
        $this->resultData = $resultData;
        $this->updatedAt = new DateTimeImmutable();

        $this->recordEvent(new ResultUpdated(
            (string) $this->id,
            $this->eventId,
            $resultData,
            $reason
        ));
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return DomainEvent[]
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function resultData(): array
    {
        return $this->resultData;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
