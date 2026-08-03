<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

/**
 * B-25: the name a participant is listed under was corrected.
 *
 * The previous name travels with the event, like the numbers of a replaced bet
 * row do. A rename is not a typo in a field - it changes who a reader thinks a
 * fee, a row or a payout share belonged to, and the history has to be able to
 * say what the name was at the time.
 */
final class ParticipantRenamed extends DomainEvent
{
    public function __construct(
        private string $participantId,
        private string $previousDisplayName,
        private string $displayName,
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
        return 'participant.renamed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'participant_id' => $this->participantId,
            'previous_display_name' => $this->previousDisplayName,
            'display_name' => $this->displayName,
        ];
    }
}
