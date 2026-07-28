<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class PayoutDistributed extends DomainEvent
{
    /**
     * @param list<array<string, mixed>> $shares participant id and amount
     */
    public function __construct(
        private string $tippYearId,
        private float $totalWinnings,
        private int $participantCount,
        private float $sharePerParticipant,
        private array $shares,
        private ?string $bookedBy = null,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->tippYearId;
    }

    public function aggregateType(): string
    {
        return 'tipp_year';
    }

    public function eventType(): string
    {
        return 'tipp_year.payout_distributed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tipp_year_id' => $this->tippYearId,
            'total_winnings' => $this->totalWinnings,
            'participant_count' => $this->participantCount,
            'share_per_participant' => $this->sharePerParticipant,
            'shares' => $this->shares,
            'booked_by' => $this->bookedBy,
        ];
    }
}
