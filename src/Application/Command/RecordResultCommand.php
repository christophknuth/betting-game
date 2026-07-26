<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class RecordResultCommand
{
    public function __construct(
        public readonly int $eventId,
        public readonly array $resultData,
        public readonly ?string $source = null,
        public readonly ?string $correlationId = null
    ) {
    }
}
