<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class CalculateScoresCommand
{
    public function __construct(
        public readonly int $eventId,
        public readonly ?string $correlationId = null
    ) {
    }
}
