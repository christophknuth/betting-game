<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class ParticipantScoreReadModel
{
    public function __construct(
        public readonly int $scoreId,
        public readonly int $participantId,
        public readonly int $bettingGameId,
        public readonly string $bettingGameName,
        public readonly int $eventId,
        public readonly string $eventName,
        public readonly ?int $pointsEarned,
        public readonly ?float $prizeAmount,
        public readonly string $calculatedAt,
        public readonly ?int $rank = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'scoreId' => $this->scoreId,
            'participantId' => $this->participantId,
            'bettingGameId' => $this->bettingGameId,
            'bettingGameName' => $this->bettingGameName,
            'eventId' => $this->eventId,
            'eventName' => $this->eventName,
            'pointsEarned' => $this->pointsEarned,
            'prizeAmount' => $this->prizeAmount,
            'calculatedAt' => $this->calculatedAt,
            'rank' => $this->rank,
        ];
    }
}
