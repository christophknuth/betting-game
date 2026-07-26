<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class AwardScoreCommand
{
    public function __construct(
        public readonly int $participantId,
        public readonly int $bettingGameId,
        public readonly int $eventId,
        public readonly ?int $pointsEarned = null,
        public readonly ?float $prizeAmount = null,
        public readonly ?string $reason = null,
        public readonly ?string $correlationId = null
    ) {
    }
}
