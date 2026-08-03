<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

/**
 * B-28: a draw was entered wrongly and put right.
 *
 * The previous date, numbers and Superzahl travel with it. A correction is the
 * one event whose interest lies in the difference - "the 41 should have been a
 * 14" is the fact, and an event carrying only the new numbers would leave the
 * audit trail to reconstruct it from the event before, which is exactly the
 * kind of joining an event log exists to avoid.
 */
final class DrawCorrected extends DomainEvent
{
    /**
     * @param list<int> $numbers
     * @param list<int> $previousNumbers
     */
    public function __construct(
        private string $drawId,
        private string $drawDate,
        private array $numbers,
        private int $superzahl,
        private string $previousDrawDate,
        private array $previousNumbers,
        private ?int $previousSuperzahl,
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
        return 'draw.corrected';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'draw_date' => $this->drawDate,
            'numbers' => $this->numbers,
            'superzahl' => $this->superzahl,
            'previous_draw_date' => $this->previousDrawDate,
            'previous_numbers' => $this->previousNumbers,
            'previous_superzahl' => $this->previousSuperzahl,
        ];
    }
}
