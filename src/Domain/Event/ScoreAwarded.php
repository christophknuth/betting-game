<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class ScoreAwarded extends DomainEvent
{
    public function __construct(
        private string $participantId,
        private int $bettingGameId,
        private int $gameEventId,
        private ?int $pointsEarned,
        private ?float $prizeAmount,
        private ?string $reason = null,
        ?string $domainEventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($domainEventId, $occurredAt, $causationId, $correlationId);
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
        return 'score.awarded';
    }

    public function bettingGameId(): int
    {
        return $this->bettingGameId;
    }

    public function gameEventId(): int
    {
        return $this->gameEventId;
    }

    public function pointsEarned(): ?int
    {
        return $this->pointsEarned;
    }

    public function prizeAmount(): ?float
    {
        return $this->prizeAmount;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function toArray(): array
    {
        return [
            'participant_id' => $this->participantId,
            'betting_game_id' => $this->bettingGameId,
            'event_id' => $this->gameEventId,
            'points_earned' => $this->pointsEarned,
            'prize_amount' => $this->prizeAmount,
            'reason' => $this->reason,
        ];
    }
}
