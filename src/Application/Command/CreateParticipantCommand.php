<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class CreateParticipantCommand
{
    public function __construct(
        public readonly int $userId,
        public readonly string $displayName,
        public readonly bool $autoApprove = false,
        public readonly ?string $correlationId = null
    ) {
    }
}
