<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class BettingGameReadModel
{
    public function __construct(
        public readonly int $bettingGameId,
        public readonly string $name,
        public readonly string $description,
        public readonly array $gameType,
        public readonly string $status,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?float $baseFee,
        public readonly ?int $feePeriodDays,
        public readonly int $participantCount,
        public readonly int $eventCount,
        public readonly ?array $configuration,
        public readonly string $createdAt
    ) {
    }

    public function toArray(): array
    {
        return [
            'bettingGameId' => $this->bettingGameId,
            'name' => $this->name,
            'description' => $this->description,
            'gameType' => $this->gameType,
            'status' => $this->status,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'baseFee' => $this->baseFee,
            'feePeriodDays' => $this->feePeriodDays,
            'participantCount' => $this->participantCount,
            'eventCount' => $this->eventCount,
            'configuration' => $this->configuration,
            'createdAt' => $this->createdAt,
        ];
    }
}
