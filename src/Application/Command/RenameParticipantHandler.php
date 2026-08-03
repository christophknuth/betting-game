<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;

/**
 * B-25: rename a participant.
 *
 * The name is the only thing about a participant that can be wrong in a way
 * worth correcting - the id is nobody's business and `user_id` belongs to a
 * table no projector writes any more. A typo used to be permanent, and it
 * showed up on every fee, every row and every payout share.
 *
 * The correction reaches everything at once: the read models join the
 * participant rather than copying the name, so the old spelling is gone from
 * the whole application the moment this is saved. What was true at the time
 * stays in the event, which carries the previous name.
 */
final class RenameParticipantHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(RenameParticipantCommand $command): CommandResult
    {
        $participant = $this->participants->findParticipant($command->participantId);

        if ($participant === null) {
            throw new EntityNotFoundException(
                "Participant {$command->participantId} does not exist"
            );
        }

        $participant->rename(new DisplayName($command->displayName));
        $this->participants->save($participant);

        return CommandResult::accepted(
            $participant->id(),
            sprintf('Participant %d renamed to %s', $participant->id(), $participant->displayName())
        );
    }
}
