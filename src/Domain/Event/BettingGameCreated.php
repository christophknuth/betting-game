<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class BettingGameCreated extends DomainEvent
{
    public function __construct(
        private string $gameId,
        private string $name,
        private int $gameTypeId,
        private string $startDate,
        private string $endDate,
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
        return 'betting_game.created';
    }

    public function name(): string
    {
        return $this->name;
    }

    public function gameTypeId(): int
    {
        return $this->gameTypeId;
    }

    public function startDate(): string
    {
        return $this->startDate;
    }

    public function endDate(): string
    {
        return $this->endDate;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'game_id' => $this->gameId,
            'name' => $this->name,
            'game_type_id' => $this->gameTypeId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }
}
