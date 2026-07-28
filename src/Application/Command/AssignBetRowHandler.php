<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\BetRow;
use BettingGame\Domain\Repository\BetPeriodRepositoryInterface;
use BettingGame\Domain\Repository\BetRowRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;

/**
 * Assigning a row for a period the participant already has one for is not an
 * update - it is an exception that needs a stated reason.
 *
 * Regularly a row changes when the next period starts, which is why the
 * default answer to "there is already a row" is a conflict, and why
 * `replaceReason` is what turns the call into a correction.
 */
final class AssignBetRowHandler
{
    public function __construct(
        private BetRowRepositoryInterface $betRows,
        private BetPeriodRepositoryInterface $betPeriods,
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(AssignBetRowCommand $command): CommandResult
    {
        if (!$this->participants->exists($command->participantId)) {
            throw new EntityNotFoundException("Participant {$command->participantId} does not exist");
        }

        if ($this->betPeriods->find($command->betPeriodId) === null) {
            throw new EntityNotFoundException("Bet period {$command->betPeriodId} does not exist");
        }

        $numbers = new LottoNumbers($command->numbers);
        $existing = $this->betRows->findByParticipantAndPeriod(
            $command->participantId,
            $command->betPeriodId
        );

        if ($existing === null) {
            $row = BetRow::assign(
                $this->betRows->nextIdentity(),
                $command->participantId,
                $command->betPeriodId,
                $numbers
            );

            $this->betRows->save($row);

            return CommandResult::accepted($row->id(), 'Bet row assigned');
        }

        if ($command->replaceReason === null || trim($command->replaceReason) === '') {
            throw new BusinessRuleViolationException(
                'This participant already has a row for this bet period. '
                . 'Supply replaceReason to correct it within the running period.'
            );
        }

        $existing->replace($numbers, $command->replaceReason);
        $this->betRows->save($existing);

        return CommandResult::accepted($existing->id(), 'Bet row replaced');
    }
}
