<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetAllPredictionsHandler
{
    public function __construct(
        private AdminPredictionReadModelRepositoryInterface $adminPredictionRepository
    ) {
    }

    public function handle(GetAllPredictionsQuery $query): QueryResult
    {
        $predictions = $this->adminPredictionRepository->findAll(
            $query->bettingGameId,
            $query->eventId,
            $query->participantId,
            $query->page,
            $query->pageSize
        );

        $totalCount = $this->adminPredictionRepository->countAll(
            $query->bettingGameId,
            $query->eventId,
            $query->participantId
        );

        $totalPages = (int) ceil($totalCount / $query->pageSize);

        return new QueryResult([
            'predictions' => array_map(fn($p) => $p->toArray(), $predictions),
            'pagination' => [
                'page' => $query->page,
                'pageSize' => $query->pageSize,
                'totalPages' => $totalPages,
                'totalCount' => $totalCount,
            ],
        ]);
    }
}
