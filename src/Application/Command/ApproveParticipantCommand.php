<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class ApproveParticipantCommand
{
    public function __construct(
        public readonly int $participantId,
        public readonly bool $approved,
        public readonly ?int $bettingGameId = null,
        public readonly ?string $notes = null,
        public readonly ?string $correlationId = null
    ) {
    }
}
