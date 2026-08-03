<?php

declare(strict_types=1);

namespace BettingGame\Domain\Service;

use BettingGame\Domain\ValueObject\EvenSplit;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use BettingGame\Domain\ValueObject\WinningClass;

/**
 * Works out what each row of a ticket achieved in a draw, and what part of the
 * ticket's winnings is attributable to it.
 *
 * This lives in the domain rather than in the command handler because it has a
 * second caller: rebuilding the draw projection has to reproduce exactly the
 * same per-row result. Two implementations would drift, and the difference
 * would only show up as money moving after a rebuild.
 *
 * The result is an attribution, not a payment. Nobody is paid per row - the
 * year's winnings are split evenly at the annual distribution.
 */
final class WinningsDistribution
{
    /**
     * @param list<array{ticketRowId: int, numbers: LottoNumbers}>        $rows
     * @param list<array{winningClass: int, amount: float}>               $breakdown
     *
     * @return list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}>
     */
    public static function of(
        LottoNumbers $drawnNumbers,
        ?Superzahl $drawnSuperzahl,
        ?Superzahl $ticketSuperzahl,
        array $rows,
        float $totalAmount,
        array $breakdown = []
    ): array {
        ['evaluated' => $evaluated, 'winnersByClass' => $winnersByClass] =
            self::evaluate($drawnNumbers, $drawnSuperzahl, $ticketSuperzahl, $rows);

        if ($winnersByClass === []) {
            return $evaluated;
        }

        return self::attribute($evaluated, $winnersByClass, $totalAmount, $breakdown);
    }

    /**
     * How many rows of the ticket achieved each winning class.
     *
     * What turns an amount per row into money: the statement says what one row
     * of a class was paid, this says how many of the ticket's rows were in it.
     * Classes no row achieved are absent rather than zero - a caller reading it
     * with `?? 0` gets the same answer and does not have to know which of the
     * nine classes exist.
     *
     * @param list<array{ticketRowId: int, numbers: LottoNumbers}> $rows
     *
     * @return array<int, int> winning class => rows of the ticket in it
     */
    public static function rowsPerClass(
        LottoNumbers $drawnNumbers,
        ?Superzahl $drawnSuperzahl,
        ?Superzahl $ticketSuperzahl,
        array $rows
    ): array {
        $winnersByClass = self::evaluate($drawnNumbers, $drawnSuperzahl, $ticketSuperzahl, $rows)['winnersByClass'];

        return array_map(count(...), $winnersByClass);
    }

    /**
     * Every row's hits and class, and which rows landed in which class.
     *
     * The Superzahl is the ticket's, not the row's: it comes off the printed
     * slip and applies to all of them at once.
     *
     * @param list<array{ticketRowId: int, numbers: LottoNumbers}> $rows
     *
     * @return array{evaluated: list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}>, winnersByClass: array<int, list<int>>}
     */
    private static function evaluate(
        LottoNumbers $drawnNumbers,
        ?Superzahl $drawnSuperzahl,
        ?Superzahl $ticketSuperzahl,
        array $rows
    ): array {
        $superzahlMatched = $drawnSuperzahl !== null
            && $ticketSuperzahl !== null
            && $ticketSuperzahl->equals($drawnSuperzahl);

        $evaluated = [];
        $winnersByClass = [];

        foreach ($rows as $index => $row) {
            $matched = $row['numbers']->matchCount($drawnNumbers);
            $winningClass = WinningClass::fromMatch($matched, $superzahlMatched)?->value();

            if ($winningClass !== null) {
                $winnersByClass[$winningClass][] = $index;
            }

            $evaluated[] = [
                'ticketRowId' => $row['ticketRowId'],
                'matchedNumbers' => $matched,
                'superzahlMatched' => $superzahlMatched,
                'winningClass' => $winningClass,
                'amount' => 0.0,
            ];
        }

        return ['evaluated' => $evaluated, 'winnersByClass' => $winnersByClass];
    }

    /**
     * With a breakdown each class's amount is split among the rows in that
     * class. Since B-23 that amount is `rows × the amount one row was paid`, so
     * the split hands every row exactly what the statement said it won - the
     * division is what also keeps the older events, whose classes carry a lump
     * sum and nothing per row, attributable in the same way.
     *
     * Without a breakdown there is no way to tell the classes apart, so the
     * total is split evenly over every winning row - an assumption, and stated
     * as one, because the lottery statement does not say it.
     *
     * @param list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}> $evaluated
     * @param array<int, list<int>>                        $winnersByClass
     * @param list<array{winningClass: int, amount: float}> $breakdown
     *
     * @return list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}>
     */
    private static function attribute(
        array $evaluated,
        array $winnersByClass,
        float $totalAmount,
        array $breakdown
    ): array {
        /** @var array<int, float> $amounts row index => its share */
        $amounts = [];

        if ($breakdown !== []) {
            foreach ($breakdown as $class) {
                $indexes = $winnersByClass[$class['winningClass']] ?? [];

                if ($indexes === []) {
                    continue;
                }

                foreach (EvenSplit::of($class['amount'], count($indexes)) as $position => $share) {
                    $amounts[$indexes[$position]] = $share;
                }
            }
        } else {
            $allWinners = array_merge(...array_values($winnersByClass));

            foreach (EvenSplit::of($totalAmount, count($allWinners)) as $position => $share) {
                $amounts[$allWinners[$position]] = $share;
            }
        }

        $settled = [];
        foreach ($evaluated as $index => $row) {
            $row['amount'] = $amounts[$index] ?? 0.0;
            $settled[] = $row;
        }

        return $settled;
    }
}
