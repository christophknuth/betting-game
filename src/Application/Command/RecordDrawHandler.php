<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;

final class RecordDrawHandler
{
    public function __construct(
        private DrawRepositoryInterface $draws,
        private TippYearRepositoryInterface $tippYears,
        private EvaluateDrawRows $evaluateRows
    ) {
    }

    public function handle(RecordDrawCommand $command): CommandResult
    {
        $tippYear = $this->tippYears->find($command->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$command->tippYearId} does not exist");
        }

        $drawDate = new DateTimeImmutable($command->drawDate);

        // A draw outside the year would never reach a ticket and would still
        // count towards the year's winnings - reject it rather than orphan it.
        if (!$tippYear->range()->contains($drawDate)) {
            throw new BusinessRuleViolationException(
                sprintf('The draw date %s is outside the tipp year %s', $drawDate->format('Y-m-d'), $tippYear->range())
            );
        }

        $draw = Draw::record(
            $this->draws->nextIdentity(),
            $command->tippYearId,
            $drawDate,
            new LottoNumbers($command->numbers),
            new Superzahl($command->superzahl)
        );

        // uk_draw_date rejects a duplicate date as a DuplicateEntryException
        $this->draws->save($draw);

        // B-22: the hits per row are known the moment the numbers are, so they
        // are worked out here rather than waiting for the winnings.
        return CommandResult::accepted($draw->id(), 'Draw recorded, ' . $this->evaluateRows->of($draw));
    }
}
