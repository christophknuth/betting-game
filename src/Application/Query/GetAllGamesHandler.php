<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetAllGamesHandler
{
    public function __construct(
        private BettingGameReadModelRepositoryInterface $bettingGameRepository
    ) {
    }

    public function handle(GetAllGamesQuery $query): QueryResult
    {
        $games = $this->bettingGameRepository->findAll(
            $query->status,
            $query->gameTypeId
        );

        return new QueryResult([
            'games' => array_map(fn($g) => $g->toArray(), $games),
        ]);
    }
}
