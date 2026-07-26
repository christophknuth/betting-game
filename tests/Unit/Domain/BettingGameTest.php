<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Model\BettingGame;
use BettingGame\Domain\Event\BettingGameCreated;
use BettingGame\Domain\Event\BettingGameEnded;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class BettingGameTest extends TestCase
{
    public function testCreateBettingGame(): void
    {
        $game = BettingGame::create(
            1,
            'Bundesliga 2024',
            'Bundesliga Tippspiel',
            1,
            new DateTimeImmutable('2024-08-01'),
            new DateTimeImmutable('2024-12-31'),
            10.00,
            30
        );

        $this->assertEquals(1, $game->id());
        $this->assertEquals('Bundesliga 2024', $game->name());
        $this->assertEquals('Bundesliga Tippspiel', $game->description());
        $this->assertEquals(1, $game->gameTypeId());
        $this->assertEquals(10.00, $game->baseFee());
        $this->assertEquals(30, $game->feePeriodDays());
        $this->assertEquals('upcoming', $game->status()->value());

        $events = $game->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BettingGameCreated::class, $events[0]);
    }

    public function testCreateBettingGameWithEndDateBeforeStartDateThrowsException(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('End date must be after start date');

        BettingGame::create(
            1,
            'Invalid Game',
            'Description',
            1,
            new DateTimeImmutable('2024-12-31'),
            new DateTimeImmutable('2024-01-01')
        );
    }

    public function testCreateBettingGameWithPointConfiguration(): void
    {
        $pointConfig = [
            'pointsExactMatch' => 5,
            'pointsCloseMatch' => 3,
            'pointsPartialMatch' => 1,
            'pointsWrong' => 0,
        ];

        $game = BettingGame::create(
            1,
            'Sports Game',
            'With points',
            1,
            new DateTimeImmutable('2024-08-01'),
            new DateTimeImmutable('2024-12-31'),
            pointConfiguration: $pointConfig
        );

        $this->assertEquals($pointConfig, $game->pointConfiguration());
        $this->assertNull($game->prizeDistribution());
    }

    public function testCreateBettingGameWithPrizeDistribution(): void
    {
        $prizeDist = [
            'totalPrizePool' => 1000.00,
            'distributionSchema' => 'percentage',
            'rankPercentages' => '50,30,20',
        ];

        $game = BettingGame::create(
            2,
            'Lottery Game',
            'With prizes',
            2,
            new DateTimeImmutable('2024-08-01'),
            new DateTimeImmutable('2024-12-31'),
            prizeDistribution: $prizeDist
        );

        $this->assertEquals($prizeDist, $game->prizeDistribution());
        $this->assertNull($game->pointConfiguration());
    }

    public function testEndBettingGame(): void
    {
        $game = BettingGame::create(
            1,
            'Test Game',
            'Description',
            1,
            new DateTimeImmutable('2024-08-01'),
            new DateTimeImmutable('2024-12-31')
        );

        $game->releaseEvents();
        $game->end('Season finished', true);

        $this->assertEquals('ended', $game->status()->value());
        $this->assertEquals(1, $game->version());

        $events = $game->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(BettingGameEnded::class, $events[0]);
        $this->assertEquals('Season finished', $events[0]->reason());
        $this->assertTrue($events[0]->finalizeScores());
    }

    public function testEndAlreadyEndedGameThrowsException(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('Game is already ended or cancelled');

        $game = BettingGame::create(
            1,
            'Test Game',
            'Description',
            1,
            new DateTimeImmutable('2024-08-01'),
            new DateTimeImmutable('2024-12-31')
        );

        $game->end('First end');
        $game->end('Second end');
    }

    public function testReleaseEventsClears(): void
    {
        $game = BettingGame::create(
            1,
            'Test',
            'Desc',
            1,
            new DateTimeImmutable('2024-08-01'),
            new DateTimeImmutable('2024-12-31')
        );

        $events = $game->releaseEvents();
        $this->assertCount(1, $events);

        $events2 = $game->releaseEvents();
        $this->assertCount(0, $events2);
    }
}
