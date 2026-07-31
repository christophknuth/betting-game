<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;

/**
 * B-21: create a participant.
 *
 * Until this existed, the only way to get a participant into the system was an
 * INSERT by hand - QUICKSTART.md said so outright, and the rows it produced
 * stood in no event, so the next projection rebuild silently dropped them.
 *
 * No `user_id` is taken. The `user` table predates Keycloak and no projector
 * writes it any more; identity comes from the token's `participant_id` claim.
 * Linking an account is E1-01's business, and inventing a link here would tie
 * new participants to rows nothing maintains.
 *
 * The participant is active immediately: approval (`ParticipantApproved`)
 * models someone signing themselves up, which is E1. What an administrator
 * enters is approved by the act of entering it.
 */
final class CreateParticipantHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(CreateParticipantCommand $command): CommandResult
    {
        $participant = Participant::create(
            $this->participants->nextIdentity(),
            null,
            new DisplayName($command->displayName),
            autoApprove: true
        );

        $this->participants->save($participant);

        return CommandResult::accepted($participant->id(), 'Participant created');
    }
}
