<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-21: the administrator enters a participant. */
final class CreateParticipantCommand
{
    public function __construct(
        public readonly string $displayName
    ) {
    }
}
