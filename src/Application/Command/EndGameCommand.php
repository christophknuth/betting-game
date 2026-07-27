<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class EndGameCommand
{
    public function __construct(
        public readonly int $bettingGameId,
        public readonly string $reason,
        public readonly bool $finalizeScores = true,
        public readonly ?string $correlationId = null
    ) {
    }
}
