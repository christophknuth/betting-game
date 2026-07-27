<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetPredictionQuery
{
    public function __construct(
        public readonly string $predictionId,
        public readonly int $participantId
    ) {
    }
}
