<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;

final class CreateParticipantHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participantRepository
    ) {
    }

    public function handle(CreateParticipantCommand $command): CommandResult
    {
        $participantId = $this->participantRepository->nextIdentity();

        $participant = Participant::create(
            $participantId,
            $command->userId,
            new DisplayName($command->displayName),
            $command->autoApprove
        );

        $this->participantRepository->save($participant);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: (string) $participantId,
            message: 'Participant creation accepted'
        );
    }
}
