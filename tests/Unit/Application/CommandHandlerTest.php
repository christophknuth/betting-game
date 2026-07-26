<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Application;

use BettingGame\Application\Command\SubmitPredictionCommand;
use BettingGame\Application\Command\SubmitPredictionHandler;
use BettingGame\Application\Command\UpdatePredictionCommand;
use BettingGame\Application\Command\UpdatePredictionHandler;
use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\Model\Prediction;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\PredictionData;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\DuplicatePredictionException;
use BettingGame\Domain\Exception\UnauthorizedAccessException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use DateTimeImmutable;

final class CommandHandlerTest extends TestCase
{
    private PredictionRepositoryInterface&MockObject $predictionRepo;
    private ParticipantRepositoryInterface&MockObject $participantRepo;
    private GameEventRepositoryInterface&MockObject $eventRepo;

    protected function setUp(): void
    {
        $this->predictionRepo = $this->createMock(PredictionRepositoryInterface::class);
        $this->participantRepo = $this->createMock(ParticipantRepositoryInterface::class);
        $this->eventRepo = $this->createMock(GameEventRepositoryInterface::class);
    }

    public function testSubmitPredictionSuccess(): void
    {
        $command = new SubmitPredictionCommand(
            participantId: 1,
            eventId: 100,
            predictionData: ['homeScore' => 2, 'awayScore' => 1]
        );

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->with(1)
            ->willReturn(true);

        $this->predictionRepo->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->predictionRepo->expects($this->once())
            ->method('nextIdentity')
            ->willReturn('pred-123');

        $this->eventRepo->expects($this->once())
            ->method('getDeadline')
            ->with(100)
            ->willReturn(new DateTimeImmutable('+1 hour'));

        $this->predictionRepo->expects($this->once())
            ->method('save');

        $handler = new SubmitPredictionHandler(
            $this->predictionRepo,
            $this->participantRepo,
            $this->eventRepo
        );

        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
        $this->assertEquals('pred-123', $result->resourceId);
    }

    public function testSubmitPredictionParticipantNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new SubmitPredictionCommand(
            participantId: 999,
            eventId: 100,
            predictionData: ['homeScore' => 2]
        );

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->with(999)
            ->willReturn(false);

        $handler = new SubmitPredictionHandler(
            $this->predictionRepo,
            $this->participantRepo,
            $this->eventRepo
        );

        $handler->handle($command);
    }

    public function testSubmitPredictionDuplicate(): void
    {
        $this->expectException(DuplicatePredictionException::class);

        $command = new SubmitPredictionCommand(
            participantId: 1,
            eventId: 100,
            predictionData: ['homeScore' => 2]
        );

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->predictionRepo->expects($this->once())
            ->method('exists')
            ->willReturn(true); // Already exists

        $handler = new SubmitPredictionHandler(
            $this->predictionRepo,
            $this->participantRepo,
            $this->eventRepo
        );

        $handler->handle($command);
    }

    public function testUpdatePredictionSuccess(): void
    {
        $predictionId = 'pred-123';
        $prediction = Prediction::submit(
            $predictionId,
            new ParticipantId(1),
            new EventId(100),
            new PredictionData(['homeScore' => 2]),
            new DateTimeImmutable('+1 hour')
        );

        $command = new UpdatePredictionCommand(
            predictionId: $predictionId,
            participantId: 1,
            predictionData: ['homeScore' => 3]
        );

        $this->predictionRepo->expects($this->once())
            ->method('findById')
            ->with($predictionId)
            ->willReturn($prediction);

        $this->eventRepo->expects($this->once())
            ->method('getDeadline')
            ->willReturn(new DateTimeImmutable('+1 hour'));

        $this->predictionRepo->expects($this->once())
            ->method('save');

        $handler = new UpdatePredictionHandler(
            $this->predictionRepo,
            $this->eventRepo
        );

        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
    }

    public function testUpdatePredictionNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new UpdatePredictionCommand(
            predictionId: 'nonexistent',
            participantId: 1,
            predictionData: ['homeScore' => 3]
        );

        $this->predictionRepo->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $handler = new UpdatePredictionHandler(
            $this->predictionRepo,
            $this->eventRepo
        );

        $handler->handle($command);
    }

    public function testUpdatePredictionUnauthorized(): void
    {
        $this->expectException(UnauthorizedAccessException::class);

        $prediction = Prediction::submit(
            'pred-123',
            new ParticipantId(1), // Owner is participant 1
            new EventId(100),
            new PredictionData(['homeScore' => 2]),
            new DateTimeImmutable('+1 hour')
        );

        $command = new UpdatePredictionCommand(
            predictionId: 'pred-123',
            participantId: 2, // Different participant trying to update
            predictionData: ['homeScore' => 3]
        );

        $this->predictionRepo->expects($this->once())
            ->method('findById')
            ->willReturn($prediction);

        $handler = new UpdatePredictionHandler(
            $this->predictionRepo,
            $this->eventRepo
        );

        $handler->handle($command);
    }
}
