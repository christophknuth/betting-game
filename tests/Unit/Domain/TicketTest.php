<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Event\TicketSubmitted;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\Ticket;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TicketTest extends TestCase
{
    /**
     * @return list<array{betRowId: int, participantId: int, numbers: LottoNumbers}>
     */
    private function rows(int $count = 3): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'betRowId' => $i,
                'participantId' => $i,
                'numbers' => new LottoNumbers([$i, $i + 10, $i + 20, $i + 25, $i + 30, $i + 40]),
            ];
        }

        return $rows;
    }

    private function submit(
        int $rowCount = 3,
        int $drawCount = 9,
        float $costPerRow = 1.20,
        float $processingFee = 0.0
    ): Ticket {
        return Ticket::submit(
            1,
            5,
            new DateTimeImmutable('2026-03-01'),
            new DateTimeImmutable('2026-03-31'),
            $drawCount,
            $costPerRow,
            $this->rows($rowCount),
            new Superzahl(4),
            'REF-2026-03',
            $processingFee
        );
    }

    public function testSubmittingRecordsAnEventWithARowSnapshot(): void
    {
        $ticket = $this->submit();

        $events = $ticket->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TicketSubmitted::class, $events[0]);

        $payload = $events[0]->toArray();
        self::assertCount(3, $payload['rows']);
        self::assertSame([1, 11, 21, 26, 31, 41], $payload['rows'][0]['numbers']);
        self::assertSame(4, $payload['superzahl']);
    }

    public function testTotalCostIsRowsTimesDrawsTimesPrice(): void
    {
        $ticket = $this->submit(rowCount: 3, drawCount: 9, costPerRow: 1.20);

        self::assertSame(32.40, $ticket->totalCost());
    }

    public function testTheFeeIsTheCostSplitEvenlyAcrossTheRows(): void
    {
        $ticket = $this->submit(rowCount: 3, drawCount: 9, costPerRow: 1.20);

        self::assertSame([10.80, 10.80, 10.80], $ticket->feeShares());
        self::assertSame(3, $ticket->rowCount());
    }

    public function testTheFeeIsRoundedToCents(): void
    {
        // 7 rows x 1 draw x 1.00 = 7.00, split by 7 -> 1.00
        $ticket = $this->submit(rowCount: 7, drawCount: 1, costPerRow: 1.00);

        self::assertSame(7.0, $ticket->totalCost());
        self::assertSame([1.0, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0], $ticket->feeShares());
    }

    public function testTheProcessingFeeIsAddedOnceForTheWholeSpielauftrag(): void
    {
        // Not per row and not per draw: the lottery company charges the
        // Bearbeitungsentgelt once for the Spielauftrag.
        $ticket = $this->submit(rowCount: 3, drawCount: 9, costPerRow: 1.20, processingFee: 1.00);

        self::assertSame(33.40, $ticket->totalCost(), '3 x 9 x 1.20 + 1.00');
        self::assertSame(1.00, $ticket->processingFee());
    }

    public function testTheOddCentOfTheProcessingFeeIsBilledRatherThanLost(): void
    {
        // 33.40 across three does not divide: 11.1333... Rounding each share
        // would bill 33.39 and lose a cent on every single ticket, so the
        // remainder goes onto the first share instead.
        $ticket = $this->submit(rowCount: 3, drawCount: 9, costPerRow: 1.20, processingFee: 1.00);

        $shares = $ticket->feeShares();

        // 3340 cents across three is 1113 each with one over, and that one
        // goes to the first share.
        self::assertSame([11.14, 11.13, 11.13], $shares);
        self::assertSame(33.40, round(array_sum($shares), 2), 'the shares have to add back up');
    }

    public function testParticipantIdsAreCollectedWithoutDuplicates(): void
    {
        $ticket = $this->submit();

        self::assertSame([1, 2, 3], $ticket->participantIds());
    }

    public function testATicketNeedsAtLeastOneRow(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        Ticket::submit(
            1,
            5,
            new DateTimeImmutable('2026-03-01'),
            new DateTimeImmutable('2026-03-31'),
            9,
            1.20,
            []
        );
    }

    public function testATicketMustCoverAtLeastOneDraw(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        Ticket::submit(
            1,
            5,
            new DateTimeImmutable('2026-03-01'),
            new DateTimeImmutable('2026-03-31'),
            0,
            1.20,
            $this->rows()
        );
    }

    public function testPeriodEndMustBeAfterPeriodStart(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        Ticket::submit(
            1,
            5,
            new DateTimeImmutable('2026-03-31'),
            new DateTimeImmutable('2026-03-01'),
            9,
            1.20,
            $this->rows()
        );
    }

    public function testASubmittedTicketIsMarkedSubmitted(): void
    {
        self::assertSame(Ticket::SUBMITTED, $this->submit()->status());
    }
}
