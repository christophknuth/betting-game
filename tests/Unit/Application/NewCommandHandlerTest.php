<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Application;

use BettingGame\Application\Command\JoinGameCommand;
use BettingGame\Application\Command\JoinGameHandler;
use BettingGame\Application\Command\LeaveGameCommand;
use BettingGame\Application\Command\LeaveGameHandler;
use BettingGame\Application\Command\CreateBettingGameCommand;
use BettingGame\Application\Command\CreateBettingGameHandler;
use BettingGame\Application\Command\EndGameCommand;
use BettingGame\Application\Command\EndGameHandler;
use BettingGame\Application\Command\RecordResultCommand;
use BettingGame\Application\Command\RecordResultHandler;
use BettingGame\Application\Command\UpdateResultCommand;
use BettingGame\Application\Command\UpdateResultHandler;
use BettingGame\Application\Command\CalculateScoresCommand;
use BettingGame\Application\Command\CalculateScoresHandler;
use BettingGame\Application\Command\AwardScoreCommand;
use BettingGame\Application\Command\AwardScoreHandler;
use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Command\ApproveParticipantCommand;
use BettingGame\Application\Command\ApproveParticipantHandler;
use BettingGame\Domain\Model\BettingGame;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Model\Result;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use BettingGame\Domain\Repository\ResultRepositoryInterface;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\Exception\EntityNotFoundException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use DateTimeImmutable;

final class NewCommandHandlerTest extends TestCase
{
    private ParticipantRepositoryInterface&MockObject $participantRepo;
    private BettingGameRepositoryInterface&MockObject $gameRepo;
    private ResultRepositoryInterface&MockObject $resultRepo;
    private GameEventRepositoryInterface&MockObject $eventRepo;

    protected function setUp(): void
    {
        $this->participantRepo = $this->createMock(ParticipantRepositoryInterface::class);
        $this->gameRepo = $this->createMock(BettingGameRepositoryInterface::class);
        $this->resultRepo = $this->createMock(ResultRepositoryInterface::class);
        $this->eventRepo = $this->createMock(GameEventRepositoryInterface::class);
    }

    // ============================
    // JoinGameHandler Tests
    // ============================

    public function testJoinGameSuccess(): void
    {
        $command = new JoinGameCommand(
            participantId: 1,
            bettingGameId: 5,
            acceptTerms: true,
            paymentReference: 'PAY-123'
        );

        $participant = Participant::create(1, 100, new DisplayName('Player'), true);
        $game = BettingGame::create(5, 'Test', 'Desc', 1, new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-12-31'));

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->with(1)
            ->willReturn(true);

        $this->gameRepo->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($game);

        $this->participantRepo->expects($this->once())
            ->method('findParticipant')
            ->with(1)
            ->willReturn($participant);

        $this->participantRepo->expects($this->once())
            ->method('save');

        $handler = new JoinGameHandler($this->participantRepo, $this->gameRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
    }

    public function testJoinGameParticipantNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new JoinGameCommand(participantId: 999, bettingGameId: 5, acceptTerms: true);

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->with(999)
            ->willReturn(false);

        $handler = new JoinGameHandler($this->participantRepo, $this->gameRepo);
        $handler->handle($command);
    }

    public function testJoinGameGameNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new JoinGameCommand(participantId: 1, bettingGameId: 999, acceptTerms: true);

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->gameRepo->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $handler = new JoinGameHandler($this->participantRepo, $this->gameRepo);
        $handler->handle($command);
    }

    // ============================
    // LeaveGameHandler Tests
    // ============================

    public function testLeaveGameSuccess(): void
    {
        $command = new LeaveGameCommand(participantId: 1, bettingGameId: 5);

        $participant = Participant::create(1, 100, new DisplayName('Player'), true);

        $this->participantRepo->expects($this->once())
            ->method('findParticipant')
            ->with(1)
            ->willReturn($participant);

        $this->participantRepo->expects($this->once())
            ->method('save');

        $handler = new LeaveGameHandler($this->participantRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
    }

    public function testLeaveGameParticipantNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new LeaveGameCommand(participantId: 999, bettingGameId: 5);

        $this->participantRepo->expects($this->once())
            ->method('findParticipant')
            ->willReturn(null);

        $handler = new LeaveGameHandler($this->participantRepo);
        $handler->handle($command);
    }

    // ============================
    // CreateBettingGameHandler Tests
    // ============================

    public function testCreateBettingGameSuccess(): void
    {
        $command = new CreateBettingGameCommand(
            name: 'Bundesliga 2024',
            description: 'Tippspiel',
            gameTypeId: 1,
            startDate: '2024-08-01',
            endDate: '2024-12-31',
            baseFee: 10.00,
            feePeriodDays: 30
        );

        $this->gameRepo->expects($this->once())
            ->method('nextIdentity')
            ->willReturn(42);

        $this->gameRepo->expects($this->once())
            ->method('save');

        $handler = new CreateBettingGameHandler($this->gameRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
        $this->assertEquals('42', $result->resourceId);
    }

    // ============================
    // EndGameHandler Tests
    // ============================

    public function testEndGameSuccess(): void
    {
        $command = new EndGameCommand(bettingGameId: 5, reason: 'Season over');

        $game = BettingGame::create(5, 'Test', 'Desc', 1, new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-12-31'));

        $this->gameRepo->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($game);

        $this->gameRepo->expects($this->once())
            ->method('save');

        $handler = new EndGameHandler($this->gameRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
        $this->assertEquals('5', $result->resourceId);
    }

    public function testEndGameNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new EndGameCommand(bettingGameId: 999, reason: 'Test');

        $this->gameRepo->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $handler = new EndGameHandler($this->gameRepo);
        $handler->handle($command);
    }

    // ============================
    // RecordResultHandler Tests
    // ============================

    public function testRecordResultSuccess(): void
    {
        $command = new RecordResultCommand(
            eventId: 100,
            resultData: ['homeScore' => 3, 'awayScore' => 1],
            source: 'official'
        );

        $this->eventRepo->expects($this->once())
            ->method('findById')
            ->with(100)
            ->willReturn(['event_id' => 100]);

        $this->resultRepo->expects($this->once())
            ->method('nextIdentity')
            ->willReturn(1);

        $this->resultRepo->expects($this->once())
            ->method('save');

        $handler = new RecordResultHandler($this->resultRepo, $this->eventRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
        $this->assertEquals('1', $result->resourceId);
    }

    public function testRecordResultEventNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new RecordResultCommand(eventId: 999, resultData: ['homeScore' => 1]);

        $this->eventRepo->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $handler = new RecordResultHandler($this->resultRepo, $this->eventRepo);
        $handler->handle($command);
    }

    // ============================
    // UpdateResultHandler Tests
    // ============================

    public function testUpdateResultSuccess(): void
    {
        $command = new UpdateResultCommand(
            eventId: 100,
            resultData: ['homeScore' => 2, 'awayScore' => 2],
            reason: 'VAR correction'
        );

        $existingResult = Result::record(1, 100, ['homeScore' => 3, 'awayScore' => 2]);

        $this->resultRepo->expects($this->once())
            ->method('findByEventId')
            ->with(100)
            ->willReturn($existingResult);

        $this->resultRepo->expects($this->once())
            ->method('save');

        $handler = new UpdateResultHandler($this->resultRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
    }

    public function testUpdateResultNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new UpdateResultCommand(eventId: 999, resultData: ['homeScore' => 1]);

        $this->resultRepo->expects($this->once())
            ->method('findByEventId')
            ->willReturn(null);

        $handler = new UpdateResultHandler($this->resultRepo);
        $handler->handle($command);
    }

    // ============================
    // CalculateScoresHandler Tests
    // ============================

    public function testCalculateScoresSuccess(): void
    {
        $command = new CalculateScoresCommand(eventId: 100);

        $this->eventRepo->expects($this->once())
            ->method('findById')
            ->with(100)
            ->willReturn(['event_id' => 100]);

        $handler = new CalculateScoresHandler($this->eventRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
    }

    public function testCalculateScoresEventNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new CalculateScoresCommand(eventId: 999);

        $this->eventRepo->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $handler = new CalculateScoresHandler($this->eventRepo);
        $handler->handle($command);
    }

    // ============================
    // AwardScoreHandler Tests
    // ============================

    public function testAwardScoreSuccess(): void
    {
        $command = new AwardScoreCommand(
            participantId: 1,
            bettingGameId: 5,
            eventId: 100,
            pointsEarned: 10,
            prizeAmount: 50.00,
            reason: 'Manual award'
        );

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->with(1)
            ->willReturn(true);

        $handler = new AwardScoreHandler($this->participantRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
    }

    public function testAwardScoreParticipantNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new AwardScoreCommand(participantId: 999, bettingGameId: 5, eventId: 100);

        $this->participantRepo->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $handler = new AwardScoreHandler($this->participantRepo);
        $handler->handle($command);
    }

    // ============================
    // CreateParticipantHandler Tests
    // ============================

    public function testCreateParticipantSuccess(): void
    {
        $command = new CreateParticipantCommand(
            userId: 100,
            displayName: 'Max Mustermann',
            autoApprove: false
        );

        $this->participantRepo->expects($this->once())
            ->method('nextIdentity')
            ->willReturn(42);

        $this->participantRepo->expects($this->once())
            ->method('save');

        $handler = new CreateParticipantHandler($this->participantRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
        $this->assertEquals('42', $result->resourceId);
    }

    public function testCreateParticipantWithAutoApprove(): void
    {
        $command = new CreateParticipantCommand(
            userId: 100,
            displayName: 'Auto User',
            autoApprove: true
        );

        $this->participantRepo->expects($this->once())
            ->method('nextIdentity')
            ->willReturn(43);

        $this->participantRepo->expects($this->once())
            ->method('save');

        $handler = new CreateParticipantHandler($this->participantRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
    }

    // ============================
    // ApproveParticipantHandler Tests
    // ============================

    public function testApproveParticipantSuccess(): void
    {
        $command = new ApproveParticipantCommand(participantId: 1, approved: true);

        $participant = Participant::create(1, 100, new DisplayName('Pending User'));

        $this->participantRepo->expects($this->once())
            ->method('findParticipant')
            ->with(1)
            ->willReturn($participant);

        $this->participantRepo->expects($this->once())
            ->method('save');

        $handler = new ApproveParticipantHandler($this->participantRepo);
        $result = $handler->handle($command);

        $this->assertEquals('accepted', $result->status);
        $this->assertEquals('Participant approved', $result->message);
    }

    public function testRejectParticipant(): void
    {
        $command = new ApproveParticipantCommand(participantId: 1, approved: false);

        $participant = Participant::create(1, 100, new DisplayName('Pending User'));

        $this->participantRepo->expects($this->once())
            ->method('findParticipant')
            ->willReturn($participant);

        $this->participantRepo->expects($this->once())
            ->method('save');

        $handler = new ApproveParticipantHandler($this->participantRepo);
        $result = $handler->handle($command);

        $this->assertEquals('Participant rejected', $result->message);
    }

    public function testApproveParticipantNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $command = new ApproveParticipantCommand(participantId: 999, approved: true);

        $this->participantRepo->expects($this->once())
            ->method('findParticipant')
            ->willReturn(null);

        $handler = new ApproveParticipantHandler($this->participantRepo);
        $handler->handle($command);
    }
}
