<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class ParticipationReadModel
{
    public function __construct(
        public readonly int $participantId,
        public readonly int $bettingGameId,
        public readonly string $bettingGameName,
        public readonly string $gameType,
        public readonly string $status,
        public readonly string $joinedAt,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $currentPoints = null,
        public readonly ?float $currentPrizeAmount = null,
        public readonly bool $feesRequired = false,
        public readonly bool $feesPaid = false
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'participantId' => $this->participantId,
            'bettingGameId' => $this->bettingGameId,
            'bettingGameName' => $this->bettingGameName,
            'gameType' => $this->gameType,
            'status' => $this->status,
            'joinedAt' => $this->joinedAt,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'currentPoints' => $this->currentPoints,
            'currentPrizeAmount' => $this->currentPrizeAmount,
            'feesRequired' => $this->feesRequired,
            'feesPaid' => $this->feesPaid,
        ];
    }
}
