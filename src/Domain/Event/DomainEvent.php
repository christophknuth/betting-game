<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

abstract class DomainEvent
{
    private string $eventId;
    private DateTimeImmutable $occurredAt;
    private ?string $causationId;
    private ?string $correlationId;

    public function __construct(
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        $this->eventId = $eventId ?? Uuid::uuid4()->toString();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
        $this->causationId = $causationId;
        $this->correlationId = $correlationId;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    abstract public function aggregateId(): string;
    
    abstract public function aggregateType(): string;

    abstract public function eventType(): string;

    /** @return array<string, mixed> */
    abstract public function toArray(): array;

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s.u'),
            'causation_id' => $this->causationId,
            'correlation_id' => $this->correlationId,
        ];
    }
}
