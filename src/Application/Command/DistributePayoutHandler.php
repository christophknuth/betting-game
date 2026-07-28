<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\EvenSplit;

/**
 * Splits the year's winnings evenly over its members.
 *
 * Evenly means evenly: how many periods someone paid for does not change their
 * share. The status rules - closed, and only once - are the aggregate's; what
 * belongs here is summing the draws and cutting the total into shares that add
 * back up to it exactly.
 */
final class DistributePayoutHandler
{
    public function __construct(
        private TippYearRepositoryInterface $tippYears,
        private DrawRepositoryInterface $draws
    ) {
    }

    public function handle(DistributePayoutCommand $command): CommandResult
    {
        if (!$command->confirm) {
            throw new BusinessRuleViolationException(
                'A distribution cannot be undone and has to be confirmed'
            );
        }

        $tippYear = $this->tippYears->find($command->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$command->tippYearId} does not exist");
        }

        $memberIds = $this->tippYears->memberIds($command->tippYearId);

        if ($memberIds === []) {
            throw new BusinessRuleViolationException('This tipp year has no members to distribute to');
        }

        $totalWinnings = $this->draws->totalWinnings($command->tippYearId);
        $amounts = EvenSplit::of($totalWinnings, count($memberIds));

        $shares = [];
        foreach ($memberIds as $position => $participantId) {
            $shares[] = [
                'participant_id' => $participantId,
                'amount' => $amounts[$position],
            ];
        }

        // The nominal share; the first participant absorbs the rounding
        // difference, so their actual amount can be a cent higher.
        $sharePerParticipant = $amounts[count($amounts) - 1];

        $tippYear->distribute(
            $totalWinnings,
            count($memberIds),
            $sharePerParticipant,
            $shares,
            $command->bookedBy
        );

        $this->tippYears->save($tippYear);

        return CommandResult::accepted(
            $tippYear->id(),
            sprintf('Distributed %.2f over %d participants', $totalWinnings, count($memberIds))
        );
    }
}
