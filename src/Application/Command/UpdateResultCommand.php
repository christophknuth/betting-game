<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class UpdateResultCommand
{
    /** @param array<string, mixed> $resultData */
    public function __construct(
        public readonly int $eventId,
        public readonly array $resultData,
        public readonly ?string $reason = null,
        public readonly ?string $correlationId = null
    ) {
    }
}
