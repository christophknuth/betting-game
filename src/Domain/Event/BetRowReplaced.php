<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

/**
 * A bet row was corrected within a running tipp year.
 *
 * Regularly a row is only changeable at the turn of the year, so this event
 * always carries a reason - it records an exception, not routine behaviour.
 */
final class BetRowReplaced extends DomainEvent
{
    /**
     * @param list<int> $previousNumbers
     * @param list<int> $numbers
     */
    public function __construct(
        private string $betRowId,
        private array $previousNumbers,
        private array $numbers,
        private string $reason,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->betRowId;
    }

    public function aggregateType(): string
    {
        return 'bet_row';
    }

    public function eventType(): string
    {
        return 'bet_row.replaced';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bet_row_id' => $this->betRowId,
            'previous_numbers' => $this->previousNumbers,
            'numbers' => $this->numbers,
            'reason' => $this->reason,
        ];
    }
}
