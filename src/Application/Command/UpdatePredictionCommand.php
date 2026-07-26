<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class UpdatePredictionCommand
{
    public function __construct(
        public readonly string $predictionId,
        public readonly int $participantId,
        public readonly array $predictionData,
        public readonly ?string $correlationId = null
    ) {
    }
}
