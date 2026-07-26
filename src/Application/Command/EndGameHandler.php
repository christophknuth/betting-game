<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class EndGameHandler
{
    public function __construct(
        private BettingGameRepositoryInterface $gameRepository
    ) {
    }

    public function handle(EndGameCommand $command): CommandResult
    {
        $game = $this->gameRepository->findById($command->bettingGameId);
        if ($game === null) {
            throw new EntityNotFoundException('Betting game not found');
        }

        $game->end($command->reason, $command->finalizeScores);

        $this->gameRepository->save($game);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: (string) $command->bettingGameId,
            message: 'Game termination accepted'
        );
    }
}
