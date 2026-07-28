<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\BetPeriod;
use BettingGame\Domain\Model\BetRow;
use BettingGame\Domain\Repository\BetPeriodRepositoryInterface;
use BettingGame\Domain\Repository\BetRowRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use DateTimeImmutable;

/**
 * Without an explicit period this answers "what am I playing right now",
 * which is the question the participant actually has.
 */
final class GetBetRowHandler
{
    public function __construct(
        private BetRowRepositoryInterface $betRows,
        private BetPeriodRepositoryInterface $betPeriods,
        private TippYearRepositoryInterface $tippYears
    ) {
    }

    public function handle(GetBetRowQuery $query, ?DateTimeImmutable $today = null): QueryResult
    {
        $today ??= new DateTimeImmutable();

        [$row, $period] = $query->betPeriodId !== null
            ? $this->byPeriod($query->participantId, $query->betPeriodId)
            : $this->currentlyRunning($query->participantId, $today);

        $tippYear = $this->tippYears->find($period->tippYearId());
        $next = $this->betPeriods->findNextAfter($period->tippYearId(), $period->range()->end());

        return new QueryResult([
            'betRowId' => $row->id(),
            'participantId' => $row->participantId(),
            'betPeriod' => [
                'betPeriodId' => $period->id(),
                'tippYearId' => $period->tippYearId(),
                'tippYearName' => $tippYear?->name(),
                'name' => $period->name(),
                'startDate' => $period->range()->start()->format('Y-m-d'),
                'endDate' => $period->range()->end()->format('Y-m-d'),
                'sequence' => $period->sequence(),
            ],
            'numbers' => $row->numbers()->toArray(),
            'assignedAt' => $row->assignedAt()->format('c'),
            // Null when no further period is planned - the row then stands
            // until the administrator defines the next one.
            'changeableFrom' => $next?->range()->start()->format('Y-m-d'),
            'ticketCount' => $this->betRows->ticketCountOf($row->id()),
        ]);
    }

    /** @return array{BetRow, BetPeriod} */
    private function byPeriod(int $participantId, int $betPeriodId): array
    {
        $period = $this->betPeriods->find($betPeriodId);

        if ($period === null) {
            throw new EntityNotFoundException("Bet period $betPeriodId does not exist");
        }

        $row = $this->betRows->findByParticipantAndPeriod($participantId, $betPeriodId);

        if ($row === null) {
            throw new EntityNotFoundException(
                "Participant $participantId has no bet row for bet period $betPeriodId"
            );
        }

        return [$row, $period];
    }

    /** @return array{BetRow, BetPeriod} */
    private function currentlyRunning(int $participantId, DateTimeImmutable $today): array
    {
        $tippYear = $this->tippYears->findCovering($today);

        if ($tippYear === null) {
            throw new EntityNotFoundException('No tipp year covers ' . $today->format('Y-m-d'));
        }

        $period = $this->betPeriods->findActiveOn($tippYear->id(), $today);

        if ($period === null) {
            throw new EntityNotFoundException('No bet period covers ' . $today->format('Y-m-d'));
        }

        $row = $this->betRows->findByParticipantAndPeriod($participantId, $period->id());

        if ($row === null) {
            throw new EntityNotFoundException(
                "Participant $participantId has no bet row for the running bet period"
            );
        }

        return [$row, $period];
    }
}
