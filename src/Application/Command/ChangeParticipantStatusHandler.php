<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;

/**
 * B-25: set a participant inactive, or active again.
 *
 * Deleting is not offered and will not be. A participant is referenced by
 * memberships, bet rows, fees and payout shares of years that have been played
 * and paid; removing the row would either take those with it or leave them
 * pointing nowhere. Somebody leaving the syndicate is not a correction of the
 * past - it is a statement about what happens next, and that is what inactive
 * says.
 */
final class ChangeParticipantStatusHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(ChangeParticipantStatusCommand $command): CommandResult
    {
        $participant = $this->participants->findParticipant($command->participantId);

        if ($participant === null) {
            throw new EntityNotFoundException(
                "Participant {$command->participantId} does not exist"
            );
        }

        $participant->changeStatus($command->isActive);
        $this->participants->save($participant);

        return CommandResult::accepted(
            $participant->id(),
            sprintf(
                'Participant %d is now %s',
                $participant->id(),
                $participant->isActive() ? 'active' : 'inactive'
            )
        );
    }
}
