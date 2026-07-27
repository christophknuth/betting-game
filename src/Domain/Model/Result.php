<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\ResultRecorded;
use BettingGame\Domain\Event\ResultUpdated;
use DateTimeImmutable;

final class Result
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    /** @param array<string, mixed> $resultData */
    private function __construct(
        private int $id,
        private int $eventId,
        private array $resultData,
        private ?string $source,
        private DateTimeImmutable $recordedAt,
        private ?DateTimeImmutable $updatedAt = null
    ) {
    }

    /** @param array<string, mixed> $resultData */
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

    /**
     * Rehydrates a result from the read model without recording events.
     *
     * @param array<string, mixed> $resultData
     */
    public static function reconstitute(
        int $id,
        int $eventId,
        array $resultData,
        ?string $source,
        DateTimeImmutable $recordedAt,
        ?DateTimeImmutable $updatedAt = null
    ): self {
        return new self($id, $eventId, $resultData, $source, $recordedAt, $updatedAt);
    }

    /** @param array<string, mixed> $resultData */
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
     * @return list<DomainEvent>
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

    /** @return array<string, mixed> */
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
