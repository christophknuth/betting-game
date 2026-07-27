<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Application\Command\AwardScoreCommand;
use BettingGame\Application\Command\AwardScoreHandler;
use BettingGame\Application\Command\CalculateScoresCommand;
use BettingGame\Application\Command\CalculateScoresHandler;
use BettingGame\Application\Command\RecordResultCommand;
use BettingGame\Application\Command\RecordResultHandler;
use BettingGame\Application\Command\UpdateResultCommand;
use BettingGame\Application\Command\UpdateResultHandler;
use BettingGame\Application\Query\GetLeaderboardHandler;
use BettingGame\Application\Query\GetLeaderboardQuery;
use BettingGame\Application\Query\GetParticipantScoresHandler;
use BettingGame\Application\Query\GetParticipantScoresQuery;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class ResultAndScoreFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBaseData();
        $this->seedGame();
        $this->seedEvent();
        $this->seedParticipant();
        $this->seedParticipant(2, 101, 'Bob');
    }

    public function testRecordingAResultPersistsTheJsonPayload(): void
    {
        $result = $this->get(RecordResultHandler::class)
            ->handle(new RecordResultCommand(42, ['homeScore' => 3, 'awayScore' => 2], 'feed'));

        self::assertSame('accepted', $result->status);

        $row = $this->fetchRow('SELECT * FROM result WHERE event_id = 42');
        self::assertSame(3, json_decode((string) $row['result_data'], true)['homeScore']);
        self::assertSame('feed', $row['source']);
        self::assertNull($row['updated_at']);
    }

    public function testUpdatingAResultSetsUpdatedAtAndAppendsAnEvent(): void
    {
        $this->get(RecordResultHandler::class)
            ->handle(new RecordResultCommand(42, ['homeScore' => 3, 'awayScore' => 2], 'feed'));

        $this->get(UpdateResultHandler::class)
            ->handle(new UpdateResultCommand(42, ['homeScore' => 4, 'awayScore' => 2], 'correction'));

        $row = $this->fetchRow('SELECT * FROM result WHERE event_id = 42');
        self::assertSame(4, json_decode((string) $row['result_data'], true)['homeScore']);
        self::assertNotNull($row['updated_at']);
        self::assertSame(1, $this->countRows('result'), 'the result is updated in place');

        $events = $this->fetchAll(
            "SELECT event_type FROM event_store WHERE aggregate_type = 'result' ORDER BY version"
        );
        self::assertSame(['result.recorded', 'result.updated'], array_column($events, 'event_type'));
    }

    public function testRecordingForAnUnknownEventFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(RecordResultHandler::class)->handle(new RecordResultCommand(999, ['x' => 1]));
    }

    public function testUpdatingAResultThatWasNeverRecordedFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(UpdateResultHandler::class)->handle(new UpdateResultCommand(42, ['x' => 1]));
    }

    public function testCalculateScoresAcknowledgesButDoesNotPersistYet(): void
    {
        $result = $this->get(CalculateScoresHandler::class)->handle(new CalculateScoresCommand(42));

        self::assertSame('accepted', $result->status);
        self::assertSame(
            0,
            $this->countRows('participant_score'),
            'CalculateScoresHandler is still a stub - this asserts the known gap, not desired behaviour'
        );
    }

    public function testCalculateScoresRejectsAnUnknownEvent(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(CalculateScoresHandler::class)->handle(new CalculateScoresCommand(999));
    }

    public function testAwardScoreAcknowledgesButDoesNotPersistYet(): void
    {
        $result = $this->get(AwardScoreHandler::class)
            ->handle(new AwardScoreCommand(1, 5, 42, 10, null, 'manual'));

        self::assertSame('accepted', $result->status);
        self::assertSame(
            0,
            $this->countRows('participant_score'),
            'AwardScoreHandler is still a stub - this asserts the known gap, not desired behaviour'
        );
    }

    public function testAwardScoreRejectsAnUnknownParticipant(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(AwardScoreHandler::class)->handle(new AwardScoreCommand(999, 5, 42, 10));
    }

    public function testParticipantScoresAggregateAcrossGames(): void
    {
        $this->pdo->exec(
            'INSERT INTO participant_score (participant_id, betting_game_id, event_id, points_earned, prize_amount)
             VALUES (1, 5, 42, 12, 4.50)'
        );

        $data = $this->get(GetParticipantScoresHandler::class)
            ->handle(new GetParticipantScoresQuery(1))->data();

        self::assertCount(1, $data['scores']);
        self::assertSame('Test Cup', $data['scores'][0]['bettingGameName']);
        self::assertSame(12, $data['summary']['totalPoints']);
        self::assertSame(4.5, $data['summary']['totalPrizeAmount']);
        self::assertSame(1, $data['summary']['gamesParticipated']);
    }

    public function testLeaderboardRanksByPointsThenPrize(): void
    {
        $this->pdo->exec(
            "INSERT INTO participant_score
                (participant_id, betting_game_id, event_id, points_earned, prize_amount, calculated_at)
             VALUES (1, 5, 42, 10, 5.00, '2026-06-02 10:00:00'),
                    (2, 5, 42, 25, 12.50, '2026-06-02 11:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO prediction (prediction_id, participant_id, event_id, prediction_data, submitted_at)
             VALUES ('p-1', 1, 42, '{\"homeScore\":1}', '2026-06-01 10:00:00')"
        );

        $data = $this->get(GetLeaderboardHandler::class)->handle(new GetLeaderboardQuery(5))->data();
        $rankings = $data['rankings'];

        self::assertSame('Test Cup', $data['bettingGameName']);
        self::assertSame(1, $rankings[0]['rank']);
        self::assertSame(2, $rankings[0]['participantId']);
        self::assertSame(25, $rankings[0]['totalPoints']);
        self::assertSame(12.5, $rankings[0]['totalPrizeAmount']);
        self::assertSame(2, $rankings[1]['rank']);
        self::assertSame(1, $rankings[1]['predictionsCount']);
        self::assertStringStartsWith('2026-06-02T11:00:00', $data['updatedAt']);
    }

    public function testLeaderboardHonoursTheLimit(): void
    {
        $this->pdo->exec(
            'INSERT INTO participant_score (participant_id, betting_game_id, event_id, points_earned)
             VALUES (1, 5, 42, 10), (2, 5, 42, 25)'
        );

        $data = $this->get(GetLeaderboardHandler::class)->handle(new GetLeaderboardQuery(5, 1))->data();

        self::assertCount(1, $data['rankings']);
        self::assertSame(2, $data['rankings'][0]['participantId']);
    }

    public function testLeaderboardForAnUnknownGameFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(GetLeaderboardHandler::class)->handle(new GetLeaderboardQuery(999));
    }
}
