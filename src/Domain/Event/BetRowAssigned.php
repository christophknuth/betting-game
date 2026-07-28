<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class BetRowAssigned extends DomainEvent
{
    /**
     * @param list<int> $numbers
     */
    public function __construct(
        private string $betRowId,
        private int $participantId,
        private int $tippYearId,
        private array $numbers,
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
        return 'bet_row.assigned';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bet_row_id' => $this->betRowId,
            'participant_id' => $this->participantId,
            'tipp_year_id' => $this->tippYearId,
            'numbers' => $this->numbers,
        ];
    }
}
