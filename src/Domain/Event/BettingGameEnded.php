<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class BettingGameEnded extends DomainEvent
{
    public function __construct(
        private string $gameId,
        private string $reason,
        private bool $finalizeScores,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->gameId;
    }

    public function aggregateType(): string
    {
        return 'betting_game';
    }

    public function eventType(): string
    {
        return 'betting_game.ended';
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function finalizeScores(): bool
    {
        return $this->finalizeScores;
    }

    public function toArray(): array
    {
        return [
            'game_id' => $this->gameId,
            'reason' => $this->reason,
            'finalize_scores' => $this->finalizeScores,
        ];
    }
}
