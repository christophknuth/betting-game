<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;

final class GetPredictionHandler
{
    public function __construct(
        private PredictionReadModelRepositoryInterface $readModelRepository
    ) {
    }

    public function handle(GetPredictionQuery $query): QueryResult
    {
        $prediction = $this->readModelRepository->findById($query->predictionId);

        // A prediction belonging to somebody else is reported as missing rather
        // than forbidden - otherwise the response would confirm that it exists.
        if ($prediction === null || $prediction->participantId !== $query->participantId) {
            throw new EntityNotFoundException('Prediction not found');
        }

        return new QueryResult($prediction->toArray());
    }
}
