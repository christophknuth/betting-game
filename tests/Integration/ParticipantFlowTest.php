<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Application\Command\ApproveParticipantCommand;
use BettingGame\Application\Command\ApproveParticipantHandler;
use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Command\JoinGameCommand;
use BettingGame\Application\Command\JoinGameHandler;
use BettingGame\Application\Command\LeaveGameCommand;
use BettingGame\Application\Command\LeaveGameHandler;
use BettingGame\Application\Query\GetParticipationsHandler;
use BettingGame\Application\Query\GetParticipationsQuery;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;

final class ParticipantFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBaseData();
        $this->seedGame();
    }

    public function testNextIdentityStartsAtOneOnAnEmptyTable(): void
    {
        self::assertSame(1, $this->get(ParticipantRepositoryInterface::class)->nextIdentity());
    }

    public function testCreatingAParticipantWritesProjectionAndEventStream(): void
    {
        $result = $this->get(CreateParticipantHandler::class)
            ->handle(new CreateParticipantCommand(100, 'Player One', false, 'corr-1'));

        self::assertSame('accepted', $result->status);
        self::assertSame('1', $result->resourceId);

        $participant = $this->fetchRow('SELECT * FROM participant WHERE participant_id = 1');
        self::assertSame('Player One', $participant['display_name']);
        self::assertSame(0, (int) $participant['is_active'], 'without autoApprove the participant stays inactive');
        self::assertSame(1, (int) $participant['version'], 'projection version mirrors the stream version');

        $event = $this->fetchRow("SELECT * FROM event_store WHERE aggregate_type = 'participant'");
        self::assertSame('participant.created', $event['event_type']);
        self::assertSame(1, (int) $event['version']);

        $stream = $this->fetchRow("SELECT * FROM event_stream WHERE stream_id = 'participant-1'");
        self::assertSame(1, (int) $stream['current_version']);
    }

    public function testApprovingActivatesTheParticipantAndBumpsTheVersion(): void
    {
        $this->get(CreateParticipantHandler::class)
            ->handle(new CreateParticipantCommand(100, 'Player One'));

        $this->get(ApproveParticipantHandler::class)
            ->handle(new ApproveParticipantCommand(1, true, null, 'looks good'));

        $participant = $this->fetchRow('SELECT * FROM participant WHERE participant_id = 1');
        self::assertSame(1, (int) $participant['is_active']);
        self::assertSame(2, (int) $participant['version']);
    }

    public function testApprovingTwiceViolatesABusinessRule(): void
    {
        $this->get(CreateParticipantHandler::class)
            ->handle(new CreateParticipantCommand(100, 'Player One', true));

        $this->expectException(BusinessRuleViolationException::class);
        $this->get(ApproveParticipantHandler::class)->handle(new ApproveParticipantCommand(1, true));
    }

    public function testApprovingAnUnknownParticipantFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(ApproveParticipantHandler::class)->handle(new ApproveParticipantCommand(999, true));
    }

    public function testJoiningAGameProjectsIntoGameParticipation(): void
    {
        $this->seedParticipant();

        $result = $this->get(JoinGameHandler::class)->handle(new JoinGameCommand(1, 5, true, 'PAY-1'));

        self::assertSame('accepted', $result->status);

        $participation = $this->fetchRow('SELECT * FROM game_participation WHERE participant_id = 1');
        self::assertSame('pending_approval', $participation['status']);
        self::assertNull($participation['left_at']);
    }

    public function testJoiningTwiceDoesNotCreateASecondParticipation(): void
    {
        $this->seedParticipant();
        $handler = $this->get(JoinGameHandler::class);

        $handler->handle(new JoinGameCommand(1, 5, true));
        $handler->handle(new JoinGameCommand(1, 5, true));

        self::assertSame(1, $this->countRows('game_participation', 'participant_id = 1'));
    }

    public function testJoiningAnUnknownGameFails(): void
    {
        $this->seedParticipant();

        $this->expectException(EntityNotFoundException::class);
        $this->get(JoinGameHandler::class)->handle(new JoinGameCommand(1, 999, true));
    }

    public function testApprovalActivatesAPendingParticipation(): void
    {
        $this->seedParticipant(active: false);
        $this->get(JoinGameHandler::class)->handle(new JoinGameCommand(1, 5, true));

        $this->get(ApproveParticipantHandler::class)->handle(new ApproveParticipantCommand(1, true));

        $participation = $this->fetchRow('SELECT * FROM game_participation WHERE participant_id = 1');
        self::assertSame('active', $participation['status']);
    }

    public function testLeavingEndsTheParticipationWithoutDeletingIt(): void
    {
        $this->seedParticipant();
        $this->get(JoinGameHandler::class)->handle(new JoinGameCommand(1, 5, true));

        $this->get(LeaveGameHandler::class)->handle(new LeaveGameCommand(1, 5));

        $participation = $this->fetchRow('SELECT * FROM game_participation WHERE participant_id = 1');
        self::assertSame('ended', $participation['status']);
        self::assertNotNull($participation['left_at']);
        self::assertSame(1, $this->countRows('game_participation'), 'the row is kept, not removed');
    }

    public function testParticipationReadModelJoinsGameFeeAndScore(): void
    {
        $this->seedParticipant();
        $this->seedEvent();
        $this->get(JoinGameHandler::class)->handle(new JoinGameCommand(1, 5, true));
        $this->pdo->exec(
            'INSERT INTO participant_score (participant_id, betting_game_id, event_id, points_earned, prize_amount)
             VALUES (1, 5, 42, 12, 4.50)'
        );

        $data = $this->get(GetParticipationsHandler::class)->handle(new GetParticipationsQuery(1))->data();
        $participation = $data['participations'][0];

        self::assertSame('Test Cup', $participation['bettingGameName']);
        self::assertSame('Football', $participation['gameType']);
        self::assertSame(12, $participation['currentPoints']);
        self::assertSame(4.5, $participation['currentPrizeAmount']);
        self::assertTrue($participation['feesRequired'], 'the game carries a base fee');
        self::assertTrue($participation['feesPaid'], 'no fee row means nothing is outstanding');
    }

    public function testParticipationReportsAnOutstandingFee(): void
    {
        $this->seedParticipant();
        $this->get(JoinGameHandler::class)->handle(new JoinGameCommand(1, 5, true));
        $this->pdo->exec(
            "INSERT INTO fee (participant_id, betting_game_id, amount, period_start, period_end, payment_status)
             VALUES (1, 5, 10.00, '2026-01-01', '2026-01-31', 'pending')"
        );

        $data = $this->get(GetParticipationsHandler::class)->handle(new GetParticipationsQuery(1))->data();
        self::assertFalse($data['participations'][0]['feesPaid']);

        $this->pdo->exec("UPDATE fee SET payment_status = 'paid' WHERE participant_id = 1");

        $data = $this->get(GetParticipationsHandler::class)->handle(new GetParticipationsQuery(1))->data();
        self::assertTrue($data['participations'][0]['feesPaid']);
    }
}
