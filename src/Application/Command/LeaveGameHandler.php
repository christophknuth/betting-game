<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class LeaveGameHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participantRepository
    ) {
    }

    public function handle(LeaveGameCommand $command): CommandResult
    {
        $participant = $this->participantRepository->findParticipant($command->participantId);
        if ($participant === null) {
            throw new EntityNotFoundException('Participant not found');
        }

        $participant->leaveGame($command->bettingGameId);

        $this->participantRepository->save($participant);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            message: 'Leave request accepted'
        );
    }
}
