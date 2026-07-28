<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\EvenSplit;
use BettingGame\Support\Row;
use DateTimeImmutable;

/**
 * Before the distribution there is no share - only a running total.
 *
 * The API says so explicitly: `amount` stays null until the payout is booked,
 * and `provisionalAmount` is the interim figure. Keeping them apart matters,
 * because the provisional one still moves with every draw.
 */
final class GetPayoutShareHandler
{
    public function __construct(
        private TippYearRepositoryInterface $tippYears,
        private DrawRepositoryInterface $draws
    ) {
    }

    public function handle(GetPayoutShareQuery $query, ?DateTimeImmutable $today = null): QueryResult
    {
        $tippYear = $query->tippYearId !== null
            ? $this->tippYears->find($query->tippYearId)
            : $this->tippYears->findCovering($today ?? new DateTimeImmutable());

        if ($tippYear === null) {
            throw new EntityNotFoundException('No such tipp year');
        }

        $totalWinnings = $this->draws->totalWinnings($tippYear->id());
        $share = $this->tippYears->payoutShareOf($tippYear->id(), $query->participantId);

        if ($share !== null) {
            return new QueryResult([
                'tippYearId' => $tippYear->id(),
                'tippYearName' => $tippYear->name(),
                'tippYearStatus' => $tippYear->status()->value(),
                'totalWinnings' => $totalWinnings,
                'participantCount' => Row::int($share, 'participant_count'),
                'amount' => Row::float($share, 'amount'),
                'provisionalAmount' => null,
                'paymentStatus' => Row::string($share, 'payment_status'),
                'distributedAt' => Row::nullableString($share, 'distributed_at'),
            ]);
        }

        $memberIds = $this->tippYears->memberIds($tippYear->id());
        $memberCount = count($memberIds);
        $position = array_search($query->participantId, $memberIds, true);

        return new QueryResult([
            'tippYearId' => $tippYear->id(),
            'tippYearName' => $tippYear->name(),
            'tippYearStatus' => $tippYear->status()->value(),
            'totalWinnings' => $totalWinnings,
            'participantCount' => $memberCount === 0 ? null : $memberCount,
            'amount' => null,
            // Computed the same way the real distribution will be, so the
            // interim figure does not jump when the payout is finally booked.
            'provisionalAmount' => $position === false || $memberCount === 0
                ? null
                : EvenSplit::of($totalWinnings, $memberCount)[$position],
            'paymentStatus' => null,
            'distributedAt' => null,
        ]);
    }
}
