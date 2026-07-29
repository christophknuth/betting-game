<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\TippYearStatus;

/**
 * B-18: the lifecycle of a tipp year over HTTP.
 *
 * The aggregate allows every transition; the rule this handler adds is the one
 * it cannot see from inside a single year - that only one of them runs at a
 * time.
 */
final class ChangeTippYearStatusHandler
{
    public function __construct(
        private TippYearRepositoryInterface $tippYears
    ) {
    }

    public function handle(ChangeTippYearStatusCommand $command): CommandResult
    {
        $tippYear = $this->tippYears->find($command->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$command->tippYearId} does not exist");
        }

        // An unknown status is rejected here, by the value object, and comes
        // back as 400 - before anything is written.
        $status = new TippYearStatus($command->status);

        $this->assertNoOtherYearIsRunning($status, $tippYear->id());

        $tippYear->changeStatusTo($status);
        $this->tippYears->save($tippYear);

        return CommandResult::accepted(
            $tippYear->id(),
            sprintf('Tipp year is now %s', $status->value())
        );
    }

    /**
     * Two years running at once would make `findCovering()` ambiguous and let a
     * draw count towards two distributions.
     *
     * This check exists for the message alone. It cannot be relied on: two
     * concurrent requests both read "nothing is running" and both proceed. What
     * actually decides is the unique key on `tipp_year.running_marker`, which
     * turns the loser into a 409 - the same answer this produces, just without
     * the name of the year in the way.
     */
    private function assertNoOtherYearIsRunning(TippYearStatus $status, int $tippYearId): void
    {
        if (!$status->isRunning()) {
            return;
        }

        $running = $this->tippYears->findRunning();

        if ($running === null || $running->id() === $tippYearId) {
            return;
        }

        throw new BusinessRuleViolationException(sprintf(
            'Tipp year %d (%s) is still running - it has to leave that status before another one enters it',
            $running->id(),
            $running->name()
        ));
    }
}
