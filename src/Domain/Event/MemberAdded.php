<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class MemberAdded extends DomainEvent
{
    public function __construct(
        private string $tippYearId,
        private int $participantId,
        private string $joinedAt,
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
        return 'tipp_year.member_added';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tipp_year_id' => $this->tippYearId,
            'participant_id' => $this->participantId,
            'joined_at' => $this->joinedAt,
        ];
    }
}
