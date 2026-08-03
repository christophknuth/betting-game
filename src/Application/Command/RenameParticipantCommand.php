<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-25: the administrator corrects the name a participant is listed under. */
final class RenameParticipantCommand
{
    public function __construct(
        public readonly int $participantId,
        public readonly string $displayName
    ) {
    }
}
