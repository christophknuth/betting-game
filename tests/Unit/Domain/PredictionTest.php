<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Model\Prediction;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\PredictionData;
use BettingGame\Domain\Event\PredictionSubmitted;
use BettingGame\Domain\Event\PredictionUpdated;
use BettingGame\Domain\Exception\DeadlinePassedException;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

final class PredictionTest extends TestCase
{
    public function testSubmitPrediction(): void
    {
        $predictionId = 'pred-123';
        $participantId = new ParticipantId(1);
        $eventId = new EventId(100);
        $predictionData = new PredictionData(['homeScore' => 2, 'awayScore' => 1]);
        $deadline = new DateTimeImmutable('+1 hour');

        $prediction = Prediction::submit(
            $predictionId,
            $participantId,
            $eventId,
            $predictionData,
            $deadline
        );

        $this->assertEquals($predictionId, $prediction->id());
        $this->assertEquals(1, $participantId->value());
        $this->assertEquals(100, $eventId->value());
        $this->assertFalse($prediction->isEvaluated());

        $events = $prediction->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PredictionSubmitted::class, $events[0]);
    }

    public function testSubmitPredictionAfterDeadlineThrowsException(): void
    {
        $this->expectException(DeadlinePassedException::class);

        $deadline = new DateTimeImmutable('-1 hour'); // Past deadline

        Prediction::submit(
            'pred-123',
            new ParticipantId(1),
            new EventId(100),
            new PredictionData(['homeScore' => 2]),
            $deadline
        );
    }

    public function testUpdatePrediction(): void
    {
        $prediction = Prediction::submit(
            'pred-123',
            new ParticipantId(1),
            new EventId(100),
            new PredictionData(['homeScore' => 2, 'awayScore' => 1]),
            new DateTimeImmutable('+1 hour')
        );

        $prediction->releaseEvents(); // Clear initial events

        $newData = new PredictionData(['homeScore' => 3, 'awayScore' => 2]);
        $prediction->update($newData, new DateTimeImmutable('+1 hour'));

        $this->assertEquals($newData->toArray(), $prediction->predictionData()->toArray());
        $this->assertEquals(1, $prediction->version());

        $events = $prediction->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PredictionUpdated::class, $events[0]);
    }

    public function testUpdateAfterDeadlineThrowsException(): void
    {
        $this->expectException(DeadlinePassedException::class);

        $prediction = Prediction::submit(
            'pred-123',
            new ParticipantId(1),
            new EventId(100),
            new PredictionData(['homeScore' => 2]),
            new DateTimeImmutable('+1 hour')
        );

        $prediction->update(
            new PredictionData(['homeScore' => 3]),
            new DateTimeImmutable('-1 hour') // Past deadline
        );
    }

    public function testEvaluatePrediction(): void
    {
        $prediction = Prediction::submit(
            'pred-123',
            new ParticipantId(1),
            new EventId(100),
            new PredictionData(['homeScore' => 2]),
            new DateTimeImmutable('+1 hour')
        );

        $prediction->evaluate(10, 5.50);

        $this->assertTrue($prediction->isEvaluated());
        $this->assertEquals(10, $prediction->pointsEarned());
        $this->assertEquals(5.50, $prediction->prizeAmount());
    }

    public function testUpdateEvaluatedPredictionThrowsException(): void
    {
        $this->expectException(DeadlinePassedException::class);

        $prediction = Prediction::submit(
            'pred-123',
            new ParticipantId(1),
            new EventId(100),
            new PredictionData(['homeScore' => 2]),
            new DateTimeImmutable('+1 hour')
        );

        $prediction->evaluate(10);

        $prediction->update(
            new PredictionData(['homeScore' => 3]),
            new DateTimeImmutable('+1 hour')
        );
    }

    public function testReconstituteFromEvents(): void
    {
        $predictionId = 'pred-123';
        $participantId = 1;
        $eventId = 100;
        $predictionData = ['homeScore' => 2, 'awayScore' => 1];

        $events = [
            new PredictionSubmitted($predictionId, $participantId, $eventId, $predictionData),
            new PredictionUpdated($predictionId, ['homeScore' => 3, 'awayScore' => 2], 1),
        ];

        $prediction = Prediction::reconstitute($events);

        $this->assertEquals($predictionId, $prediction->id());
        $this->assertEquals(1, $prediction->version());
        $this->assertEquals(['homeScore' => 3, 'awayScore' => 2], $prediction->predictionData()->toArray());
    }
}
