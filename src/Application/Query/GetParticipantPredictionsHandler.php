<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetParticipantPredictionsHandler
{
    public function __construct(
        private PredictionReadModelRepositoryInterface $readModelRepository
    ) {
    }

    public function handle(GetParticipantPredictionsQuery $query): QueryResult
    {
        $predictions = $this->readModelRepository->findByParticipant(
            $query->participantId,
            $query->bettingGameId,
            $query->eventId,
            $query->status
        );

        return new QueryResult([
            'predictions' => array_map(fn($p) => $p->toArray(), $predictions),
            'totalCount' => count($predictions),
        ]);
    }
}
