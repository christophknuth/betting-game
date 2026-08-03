<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-25: the administrator sets a participant inactive, or active again. */
final class ChangeParticipantStatusCommand
{
    public function __construct(
        public readonly int $participantId,
        public readonly bool $isActive
    ) {
    }
}
