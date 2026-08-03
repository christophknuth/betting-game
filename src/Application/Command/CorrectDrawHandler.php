<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;

/**
 * B-28: puts a mistyped draw right, as long as nothing is booked against it.
 *
 * Whether it may be corrected at all is the aggregate's decision - `evaluated`
 * refuses. What is checked here is what needs the year: a date moved outside
 * the tipp year would leave the draw belonging to nothing while still counting
 * towards the year's total, which is the same rule B-08 applies when the draw
 * is first recorded.
 *
 * The rows are evaluated again afterwards, through the same service B-08 uses.
 * They have to be: the hits follow from the numbers, and a correction that left
 * the old ones in place would produce a draw whose winning classes belong to
 * numbers nobody can see any more.
 */
final class CorrectDrawHandler
{
    public function __construct(
        private DrawRepositoryInterface $draws,
        private TippYearRepositoryInterface $tippYears,
        private EvaluateDrawRows $evaluateRows
    ) {
    }

    public function handle(CorrectDrawCommand $command): CommandResult
    {
        $draw = $this->draws->find($command->drawId);

        if ($draw === null) {
            throw new EntityNotFoundException("Draw {$command->drawId} does not exist");
        }

        $tippYear = $this->tippYears->find($draw->tippYearId());

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$draw->tippYearId()} does not exist");
        }

        $drawDate = new DateTimeImmutable($command->drawDate);

        if (!$tippYear->range()->contains($drawDate)) {
            throw new BusinessRuleViolationException(sprintf(
                'The draw date %s is outside the tipp year %s',
                $drawDate->format('Y-m-d'),
                $tippYear->range()
            ));
        }

        $draw->correct($drawDate, new LottoNumbers($command->numbers), new Superzahl($command->superzahl));

        // uk_draw_date rejects a move onto a day that already has a draw
        $this->draws->save($draw);

        return CommandResult::accepted($draw->id(), 'Draw corrected, ' . $this->evaluateRows->of($draw));
    }
}
