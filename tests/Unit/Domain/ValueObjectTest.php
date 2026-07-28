<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\ValueObject\Email;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\Superzahl;
use BettingGame\Domain\ValueObject\TippYearStatus;
use BettingGame\Domain\ValueObject\WinningClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueObjectTest extends TestCase
{
    // ----------------------------------------------------------- ParticipantId

    public function testParticipantIdKeepsItsValue(): void
    {
        self::assertSame(123, (new ParticipantId(123))->value());
    }

    public function testParticipantIdRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ParticipantId(0);
    }

    public function testParticipantIdRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ParticipantId(-1);
    }

    // ------------------------------------------------------------ LottoNumbers

    public function testLottoNumbersAreStoredSorted(): void
    {
        $numbers = new LottoNumbers([45, 3, 27, 12, 33, 19]);

        self::assertSame([3, 12, 19, 27, 33, 45], $numbers->toArray());
    }

    public function testLottoNumbersRejectTooFew(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LottoNumbers([1, 2, 3, 4, 5]);
    }

    public function testLottoNumbersRejectTooMany(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LottoNumbers([1, 2, 3, 4, 5, 6, 7]);
    }

    public function testLottoNumbersRejectDuplicates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LottoNumbers([1, 2, 3, 4, 5, 5]);
    }

    #[DataProvider('outOfRangeProvider')]
    public function testLottoNumbersRejectOutOfRange(int $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LottoNumbers([$number, 2, 3, 4, 5, 6]);
    }

    /** @return array<string, array{0: int}> */
    public static function outOfRangeProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'fifty' => [50]];
    }

    public function testLottoNumbersFromMixedAcceptsNumericStrings(): void
    {
        $numbers = LottoNumbers::fromMixed(['7', 3, '41', 22, 15, '9']);

        self::assertSame([3, 7, 9, 15, 22, 41], $numbers->toArray());
    }

    public function testLottoNumbersFromMixedRejectsNonNumeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LottoNumbers::fromMixed(['seven', 3, 41, 22, 15, 9]);
    }

    public function testLottoNumbersCountMatches(): void
    {
        $row = new LottoNumbers([3, 12, 19, 27, 33, 45]);
        $drawn = new LottoNumbers([3, 12, 19, 30, 40, 49]);

        self::assertSame(3, $row->matchCount($drawn));
        self::assertSame(6, $row->matchCount($row));
    }

    public function testLottoNumbersEqualityIgnoresInputOrder(): void
    {
        self::assertTrue(
            (new LottoNumbers([1, 2, 3, 4, 5, 6]))->equals(new LottoNumbers([6, 5, 4, 3, 2, 1]))
        );
    }

    public function testLottoNumbersRenderTwoDigits(): void
    {
        self::assertSame(
            '03 - 12 - 19 - 27 - 33 - 45',
            (string) new LottoNumbers([3, 12, 19, 27, 33, 45])
        );
    }

    // --------------------------------------------------------------- Superzahl

    public function testSuperzahlAcceptsTheFullRange(): void
    {
        self::assertSame(0, (new Superzahl(0))->value());
        self::assertSame(9, (new Superzahl(9))->value());
    }

    public function testSuperzahlRejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Superzahl(10);
    }

    public function testSuperzahlEquality(): void
    {
        self::assertTrue((new Superzahl(4))->equals(new Superzahl(4)));
        self::assertFalse((new Superzahl(4))->equals(new Superzahl(5)));
    }

    // ---------------------------------------------------------- TippYearStatus

    public function testTippYearStatusExposesTheLifecycle(): void
    {
        self::assertTrue((new TippYearStatus(TippYearStatus::RUNNING))->isRunning());
        self::assertTrue((new TippYearStatus(TippYearStatus::CLOSED))->isClosed());
        self::assertTrue((new TippYearStatus(TippYearStatus::DISTRIBUTED))->isDistributed());
    }

    public function testOnlyARunningYearAcceptsTickets(): void
    {
        self::assertTrue((new TippYearStatus(TippYearStatus::RUNNING))->acceptsTickets());
        self::assertFalse((new TippYearStatus(TippYearStatus::PLANNED))->acceptsTickets());
        self::assertFalse((new TippYearStatus(TippYearStatus::CLOSED))->acceptsTickets());
    }

    public function testTippYearStatusRejectsUnknownValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TippYearStatus('finished');
    }

    // ------------------------------------------------------------ WinningClass

    #[DataProvider('winningClassProvider')]
    public function testWinningClassIsDerivedFromTheMatch(
        int $matched,
        bool $superzahl,
        ?int $expected
    ): void {
        self::assertSame($expected, WinningClass::fromMatch($matched, $superzahl)?->value());
    }

    /** @return array<string, array{0: int, 1: bool, 2: int|null}> */
    public static function winningClassProvider(): array
    {
        return [
            '6 + SZ' => [6, true, 1],
            '6' => [6, false, 2],
            '5 + SZ' => [5, true, 3],
            '5' => [5, false, 4],
            '4 + SZ' => [4, true, 5],
            '4' => [4, false, 6],
            '3 + SZ' => [3, true, 7],
            '3' => [3, false, 8],
            '2 + SZ' => [2, true, 9],
            '2 without SZ wins nothing' => [2, false, null],
            '1 + SZ wins nothing' => [1, true, null],
            'nothing' => [0, false, null],
        ];
    }

    public function testWinningClassRejectsImpossibleMatchCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WinningClass::fromMatch(7, false);
    }

    // ------------------------------------------------------- DisplayName/Email

    public function testDisplayNameIsTrimmed(): void
    {
        self::assertSame('Alice', (new DisplayName('  Alice  '))->value());
    }

    public function testDisplayNameRejectsTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DisplayName('A');
    }

    public function testDisplayNameRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DisplayName(str_repeat('A', 51));
    }

    public function testEmailIsNormalised(): void
    {
        self::assertSame('alice@example.com', (new Email('Alice@Example.COM'))->value());
    }

    public function testEmailRejectsInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Email('not-an-email');
    }
}
