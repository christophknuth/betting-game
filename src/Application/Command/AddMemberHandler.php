<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;

final class AddMemberHandler
{
    public function __construct(
        private TippYearRepositoryInterface $tippYears,
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(AddMemberCommand $command): CommandResult
    {
        $tippYear = $this->tippYears->find($command->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$command->tippYearId} does not exist");
        }

        $participant = $this->participants->findParticipant($command->participantId);

        if ($participant === null) {
            throw new EntityNotFoundException("Participant {$command->participantId} does not exist");
        }

        // B-25: inactive means "plays no more". Past memberships stay untouched,
        // but a new one is exactly what the flag is there to prevent - and the
        // picker no longer offers them either.
        if (!$participant->isActive()) {
            throw new BusinessRuleViolationException(
                "Participant {$command->participantId} is inactive and cannot join a tipp year"
            );
        }

        // The membership projection would happily reactivate an existing row,
        // so the duplicate has to be caught before the event is recorded.
        if ($this->tippYears->isMember($command->tippYearId, $command->participantId)) {
            throw new BusinessRuleViolationException(
                "Participant {$command->participantId} is already a member of this tipp year"
            );
        }

        $tippYear->addMember($command->participantId);
        $this->tippYears->save($tippYear);

        return CommandResult::accepted($command->participantId, 'Member added');
    }
}
