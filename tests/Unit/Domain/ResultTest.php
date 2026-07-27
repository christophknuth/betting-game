<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Model\Result;
use BettingGame\Domain\Event\ResultRecorded;
use BettingGame\Domain\Event\ResultUpdated;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testRecordResult(): void
    {
        $result = Result::record(
            1,
            100,
            ['homeScore' => 3, 'awayScore' => 2],
            'manual'
        );

        $this->assertEquals(1, $result->id());
        $this->assertEquals(100, $result->eventId());
        $this->assertEquals(['homeScore' => 3, 'awayScore' => 2], $result->resultData());
        $this->assertEquals('manual', $result->source());
        $this->assertNotNull($result->recordedAt());
        $this->assertNull($result->updatedAt());

        $events = $result->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ResultRecorded::class, $events[0]);
        $this->assertEquals(100, $events[0]->gameEventId());
    }

    public function testRecordResultWithoutSource(): void
    {
        $result = Result::record(
            1,
            100,
            ['homeScore' => 1, 'awayScore' => 0]
        );

        $this->assertNull($result->source());
    }

    public function testUpdateResult(): void
    {
        $result = Result::record(
            1,
            100,
            ['homeScore' => 3, 'awayScore' => 2]
        );

        $result->releaseEvents();
        $result->update(['homeScore' => 2, 'awayScore' => 2], 'VAR correction');

        $this->assertEquals(['homeScore' => 2, 'awayScore' => 2], $result->resultData());
        $this->assertNotNull($result->updatedAt());

        $events = $result->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ResultUpdated::class, $events[0]);
        $this->assertEquals('VAR correction', $events[0]->reason());
        $this->assertEquals(100, $events[0]->gameEventId());
    }

    public function testUpdateResultWithoutReason(): void
    {
        $result = Result::record(1, 100, ['homeScore' => 1, 'awayScore' => 0]);
        $result->releaseEvents();

        $result->update(['homeScore' => 2, 'awayScore' => 0]);

        $events = $result->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->reason());
    }

    public function testReleaseEventsClears(): void
    {
        $result = Result::record(1, 100, ['homeScore' => 1, 'awayScore' => 0]);

        $events = $result->releaseEvents();
        $this->assertCount(1, $events);

        $events2 = $result->releaseEvents();
        $this->assertCount(0, $events2);
    }
}
