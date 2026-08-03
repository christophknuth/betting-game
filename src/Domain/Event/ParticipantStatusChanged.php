<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

/**
 * B-25: a participant was set inactive, or active again.
 *
 * Inactive means "plays no more", not "never existed": memberships, fees and
 * rows of past years stay exactly as they are. What changes is the future -
 * an inactive participant is not offered for a tipp year and cannot be added
 * to one.
 *
 * Distinct from `ParticipantApproved`, which is E1's approval of somebody
 * signing themselves up. Both end up in the same column; they answer different
 * questions, and an audit trail that cannot tell them apart is worth less than
 * one event type saved.
 */
final class ParticipantStatusChanged extends DomainEvent
{
    public function __construct(
        private string $participantId,
        private bool $isActive,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->participantId;
    }

    public function aggregateType(): string
    {
        return 'participant';
    }

    public function eventType(): string
    {
        return 'participant.status_changed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'participant_id' => $this->participantId,
            'is_active' => $this->isActive,
        ];
    }
}
