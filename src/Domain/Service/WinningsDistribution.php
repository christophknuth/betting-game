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

        if ($winnersByClass === []) {
            return $evaluated;
        }

        return self::attribute($evaluated, $winnersByClass, $totalAmount, $breakdown);
    }

    /**
     * With a breakdown each class's amount is split among the rows in that
     * class. Without one there is no way to tell the classes apart, so the
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
