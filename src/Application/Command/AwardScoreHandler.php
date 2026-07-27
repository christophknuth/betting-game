<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class AwardScoreHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participantRepository
    ) {
    }

    public function handle(AwardScoreCommand $command): CommandResult
    {
        if (!$this->participantRepository->exists($command->participantId)) {
            throw new EntityNotFoundException('Participant not found');
        }

        // In production, this would persist the score to ParticipantScore table
        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            message: 'Score award accepted'
        );
    }
}
