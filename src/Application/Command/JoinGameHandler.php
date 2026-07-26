<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class JoinGameHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participantRepository,
        private BettingGameRepositoryInterface $gameRepository
    ) {
    }

    public function handle(JoinGameCommand $command): CommandResult
    {
        if (!$this->participantRepository->exists($command->participantId)) {
            throw new EntityNotFoundException('Participant not found');
        }

        $game = $this->gameRepository->findById($command->bettingGameId);
        if ($game === null) {
            throw new EntityNotFoundException('Betting game not found');
        }

        $participant = $this->participantRepository->findParticipant($command->participantId);
        if ($participant === null) {
            throw new EntityNotFoundException('Participant not found');
        }

        $participant->joinGame(
            $command->bettingGameId,
            $command->acceptTerms,
            $command->paymentReference
        );

        $this->participantRepository->save($participant);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            message: 'Join request accepted'
        );
    }
}
