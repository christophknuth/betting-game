<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class LeaveGameCommand
{
    public function __construct(
        public readonly int $participantId,
        public readonly int $bettingGameId,
        public readonly ?string $correlationId = null
    ) {
    }
}
