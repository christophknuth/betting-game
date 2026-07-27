<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class SubmitPredictionCommand
{
    /** @param array<string, mixed> $predictionData */
    public function __construct(
        public readonly int $participantId,
        public readonly int $eventId,
        public readonly array $predictionData,
        public readonly ?string $correlationId = null
    ) {
    }
}
