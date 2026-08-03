<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;

/**
 * E1-01: a signed-in account asks to play.
 *
 * The registration creates a **pending** participant - a request, not a member.
 * An administrator decides through B-25's status route, where saying yes to a
 * pending participant is recorded as the approval it is.
 *
 * Two things it deliberately does not do. It takes **no participant id from
 * the caller**: the account comes off the token, so nobody can register in
 * somebody else's name. And it does not touch Keycloak - the realm decides who
 * gets a login, this decides who plays, and keeping the two apart is what
 * allows an account to exist without a participant and the other way round.
 */
final class RegisterParticipantHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(RegisterParticipantCommand $command): CommandResult
    {
        $existing = $this->participants->findByKeycloakSubject($command->keycloakSubject);

        // Checked here for the sentence; uk_keycloak_subject is what actually
        // holds it, and would fire on two registrations arriving at once.
        if ($existing !== null) {
            throw new BusinessRuleViolationException(
                $existing->status()->isPending()
                    ? 'This account has already registered and is waiting for approval'
                    : 'This account already belongs to a participant'
            );
        }

        $participant = Participant::register(
            $this->participants->nextIdentity(),
            $command->keycloakSubject,
            new DisplayName($command->displayName)
        );

        $this->participants->save($participant);

        return CommandResult::accepted(
            $participant->id(),
            sprintf('Registration of %s recorded, waiting for approval', $participant->displayName())
        );
    }
}
