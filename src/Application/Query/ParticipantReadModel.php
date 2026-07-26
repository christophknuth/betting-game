<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class ParticipantReadModel
{
    public function __construct(
        public readonly int $participantId,
        public readonly int $userId,
        public readonly string $displayName,
        public readonly string $status,
        public readonly string $registeredAt,
        public readonly int $gamesParticipated,
        public readonly int $totalPoints,
        public readonly float $totalPrizes
    ) {
    }

    public function toArray(): array
    {
        return [
            'participantId' => $this->participantId,
            'userId' => $this->userId,
            'displayName' => $this->displayName,
            'status' => $this->status,
            'registeredAt' => $this->registeredAt,
            'gamesParticipated' => $this->gamesParticipated,
            'totalPoints' => $this->totalPoints,
            'totalPrizes' => $this->totalPrizes,
        ];
    }
}
