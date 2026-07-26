<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class ParticipantJoinedGame extends DomainEvent
{
    public function __construct(
        private string $participantId,
        private int $bettingGameId,
        private bool $acceptedTerms,
        private ?string $paymentReference = null,
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
        return 'participant.joined_game';
    }

    public function bettingGameId(): int
    {
        return $this->bettingGameId;
    }

    public function acceptedTerms(): bool
    {
        return $this->acceptedTerms;
    }

    public function paymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function toArray(): array
    {
        return [
            'participant_id' => $this->participantId,
            'betting_game_id' => $this->bettingGameId,
            'accepted_terms' => $this->acceptedTerms,
            'payment_reference' => $this->paymentReference,
        ];
    }
}
