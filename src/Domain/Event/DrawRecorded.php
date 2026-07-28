<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class DrawRecorded extends DomainEvent
{
    /**
     * @param list<int> $numbers
     */
    public function __construct(
        private string $drawId,
        private int $tippYearId,
        private string $drawDate,
        private array $numbers,
        private int $superzahl,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->drawId;
    }

    public function aggregateType(): string
    {
        return 'draw';
    }

    public function eventType(): string
    {
        return 'draw.recorded';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'tipp_year_id' => $this->tippYearId,
            'draw_date' => $this->drawDate,
            'numbers' => $this->numbers,
            'superzahl' => $this->superzahl,
        ];
    }
}
