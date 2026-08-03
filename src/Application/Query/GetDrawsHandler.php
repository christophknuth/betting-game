<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Support\Row;

/**
 * The winnings shown here are the whole ticket's, not the caller's share.
 *
 * That is deliberate and worth stating: a participant's share only comes into
 * existence with the annual distribution, so during the year there is nothing
 * personal to show - only what the syndicate won together.
 *
 * B-24 adds the rows the ticket carried into the draw, and with them the reason
 * the ticket is now joined by its period rather than through the result row:
 * the rows took part in the draw whether or not anyone has recorded what they
 * won, and until that happens `totalAmount` is null rather than zero.
 *
 * B-26 adds which slip that was. Two Spielaufträge can cover the same date, so
 * "the ticket" is a choice - and the Losnummer is what lets a reader check it
 * against the slip in their hand rather than take the word for it.
 */
final class GetDrawsHandler
{
    public function __construct(
        private DrawRepositoryInterface $draws,
        private TippYearRepositoryInterface $tippYears
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
                    // B-26: the Losnummer identifies the Spielauftrag on the
                    // slip, and its last digit is the Superzahl - the one every
                    // row of this ticket is measured against, as opposed to the
                    // one drawn above.
                    'lotteryReference' => Row::nullableString($row, 'lottery_reference'),
                    'superzahl' => Row::nullableInt($row, 'ticket_superzahl'),
                    'rowCount' => Row::nullableInt($row, 'row_count') ?? 0,
                    // Null, not 0.00, for as long as no winnings are recorded:
                    // zero is a statement about a draw somebody has looked at.
                    'totalAmount' => $totalAmount,
                    'winningClasses' => $this->draws->winningClassesOf($drawId),
                    'bestMatch' => $this->draws->bestMatchOf($drawId),
                    // B-24: the rows themselves, so that a draw can be read
                    // against what the syndicate actually played
                    'rows' => $this->draws->rowResultsOf($drawId, $ticketId),
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
