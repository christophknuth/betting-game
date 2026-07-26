<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Model\BettingGame;
use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use DateTimeImmutable;

final class CreateBettingGameHandler
{
    public function __construct(
        private BettingGameRepositoryInterface $gameRepository
    ) {
    }

    public function handle(CreateBettingGameCommand $command): CommandResult
    {
        $gameId = $this->gameRepository->nextIdentity();

        $game = BettingGame::create(
            $gameId,
            $command->name,
            $command->description,
            $command->gameTypeId,
            new DateTimeImmutable($command->startDate),
            new DateTimeImmutable($command->endDate),
            $command->baseFee,
            $command->feePeriodDays,
            $command->pointConfiguration,
            $command->prizeDistribution
        );

        $this->gameRepository->save($game);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: (string) $gameId,
            message: 'Game creation accepted'
        );
    }
}
