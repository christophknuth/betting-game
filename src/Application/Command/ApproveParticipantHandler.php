<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class ApproveParticipantHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participantRepository
    ) {
    }

    public function handle(ApproveParticipantCommand $command): CommandResult
    {
        $participant = $this->participantRepository->findParticipant($command->participantId);
        if ($participant === null) {
            throw new EntityNotFoundException('Participant not found');
        }

        if ($command->approved) {
            $participant->approve();
        }

        $this->participantRepository->save($participant);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: (string) $command->participantId,
            message: $command->approved ? 'Participant approved' : 'Participant rejected'
        );
    }
}
