<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;

final class GetGameDetailsHandler
{
    public function __construct(
        private BettingGameReadModelRepositoryInterface $bettingGameRepository
    ) {
    }

    public function handle(GetGameDetailsQuery $query): QueryResult
    {
        $game = $this->bettingGameRepository->findById($query->bettingGameId);

        if ($game === null) {
            throw new EntityNotFoundException('Betting game not found');
        }

        return new QueryResult($game->toArray());
    }
}
