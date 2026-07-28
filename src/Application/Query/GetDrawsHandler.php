<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Support\Row;

/**
 * The winnings shown here are the whole ticket's, not the caller's share.
 *
 * That is deliberate and worth stating: a participant's share only comes into
 * existence with the annual distribution, so during the year there is nothing
 * personal to show - only what the syndicate won together.
 */
final class GetDrawsHandler
{
    public function __construct(
        private DrawRepositoryInterface $draws,
        private TippYearRepositoryInterface $tippYears,
        private TicketRepositoryInterface $tickets
    ) {
    }

    public function handle(GetDrawsQuery $query): QueryResult
    {
        if ($this->tippYears->find($query->tippYearId) === null) {
            throw new EntityNotFoundException("Tipp year {$query->tippYearId} does not exist");
        }

        $draws = [];

        foreach ($this->draws->findWithWinnings($query->tippYearId) as $row) {
            $status = Row::string($row, 'status');
            $ticketId = Row::nullableInt($row, 'ticket_id');
            $totalAmount = Row::nullableFloat($row, 'total_amount');

            if ($query->status !== null && $status !== $query->status) {
                continue;
            }

            if ($query->withWinningsOnly && ($totalAmount === null || $totalAmount <= 0.0)) {
                continue;
            }

            $drawId = Row::int($row, 'draw_id');
            $numbers = $row['numbers'] ?? null;

            $draws[] = [
                'drawId' => $drawId,
                'drawDate' => Row::string($row, 'draw_date'),
                'numbers' => $numbers === null
                    ? null
                    : LottoNumbers::fromMixed(Row::json($row, 'numbers'))->toArray(),
                'superzahl' => Row::nullableInt($row, 'superzahl'),
                'status' => $status,
                'ticket' => $ticketId === null ? null : [
                    'ticketId' => $ticketId,
                    'rowCount' => $this->tickets->find($ticketId)?->rowCount() ?? 0,
                    'totalAmount' => $totalAmount ?? 0.0,
                    'winningClasses' => $this->draws->winningClassesOf($drawId),
                    'bestMatch' => $this->draws->bestMatchOf($drawId),
                ],
            ];
        }

        return new QueryResult([
            'tippYearId' => $query->tippYearId,
            'draws' => $draws,
            // Always the year's full total, independent of the filters above -
            // a filtered list must not look like a smaller year.
            'totalWinnings' => $this->draws->totalWinnings($query->tippYearId),
        ]);
    }
}
