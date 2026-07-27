<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Application\Command\SubmitPredictionCommand;
use BettingGame\Application\Command\SubmitPredictionHandler;
use BettingGame\Application\Command\UpdatePredictionCommand;
use BettingGame\Application\Command\UpdatePredictionHandler;
use BettingGame\Application\Query\GetAllPredictionsHandler;
use BettingGame\Application\Query\GetAllPredictionsQuery;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Application\Query\GetParticipantPredictionsQuery;
use BettingGame\Application\Query\GetPredictionHandler;
use BettingGame\Application\Query\GetPredictionQuery;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\ParticipantId;

final class PredictionFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBaseData();
        $this->seedGame();
        $this->seedEvent();
        $this->seedParticipant();
    }

    private function submit(): string
    {
        $result = $this->get(SubmitPredictionHandler::class)
            ->handle(new SubmitPredictionCommand(1, 42, ['homeScore' => 2, 'awayScore' => 1]));

        self::assertNotNull($result->resourceId);

        return $result->resourceId;
    }

    public function testNextIdentityIsAUuid(): void
    {
        $id = $this->get(PredictionRepositoryInterface::class)->nextIdentity();

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    public function testSubmittingWritesProjectionAndEvent(): void
    {
        $this->submit();

        $prediction = $this->fetchRow('SELECT * FROM prediction WHERE participant_id = 1 AND event_id = 42');
        $data = json_decode((string) $prediction['prediction_data'], true);

        self::assertSame(['homeScore' => 2, 'awayScore' => 1], $data);
        self::assertSame(1, (int) $prediction['version']);

        $event = $this->fetchRow("SELECT * FROM event_store WHERE aggregate_type = 'prediction'");
        self::assertSame('prediction.submitted', $event['event_type']);
    }

    public function testExistsReflectsTheSubmission(): void
    {
        $repo = $this->get(PredictionRepositoryInterface::class);

        self::assertFalse($repo->exists(new ParticipantId(1), new EventId(42)));
        $this->submit();
        self::assertTrue($repo->exists(new ParticipantId(1), new EventId(42)));
    }

    public function testFindByParticipantAndByEventBothReturnThePrediction(): void
    {
        $this->submit();
        $repo = $this->get(PredictionRepositoryInterface::class);

        self::assertCount(1, $repo->findByParticipant(new ParticipantId(1)));
        self::assertCount(1, $repo->findByEvent(new EventId(42)));
        self::assertCount(0, $repo->findByParticipant(new ParticipantId(999)));
    }

    public function testUpdatingBumpsTheVersionAndAppendsASecondEvent(): void
    {
        $predictionId = $this->submit();

        $this->get(UpdatePredictionHandler::class)
            ->handle(new UpdatePredictionCommand($predictionId, 1, ['homeScore' => 3, 'awayScore' => 0]));

        $prediction = $this->fetchRow('SELECT * FROM prediction WHERE participant_id = 1');
        self::assertSame(3, json_decode((string) $prediction['prediction_data'], true)['homeScore']);
        self::assertSame(2, (int) $prediction['version']);
        self::assertNotNull($prediction['updated_at']);

        $events = $this->fetchAll(
            "SELECT event_type FROM event_store WHERE aggregate_type = 'prediction' ORDER BY version"
        );
        self::assertCount(2, $events);
        self::assertSame('prediction.updated', $events[1]['event_type']);
    }

    public function testTheReadModelExposesTheDecodedPrediction(): void
    {
        $this->submit();

        $data = $this->get(GetParticipantPredictionsHandler::class)
            ->handle(new GetParticipantPredictionsQuery(1))->data();
        $prediction = $data['predictions'][0];

        self::assertSame(2, $prediction['predictionData']['homeScore']);
        self::assertSame('Final', $prediction['eventName']);
        self::assertTrue($prediction['isEditable'], 'the deadline is in the future');
        self::assertSame('submitted', $prediction['status']);
    }

    public function testARecordedResultMarksThePredictionEvaluated(): void
    {
        $this->submit();
        $this->pdo->exec(
            'INSERT INTO result (event_id, result_data, source) VALUES (42, \'{"homeScore":3}\', \'feed\')'
        );

        $data = $this->get(GetParticipantPredictionsHandler::class)
            ->handle(new GetParticipantPredictionsQuery(1))->data();

        self::assertSame('evaluated', $data['predictions'][0]['status']);
        self::assertFalse($data['predictions'][0]['isEditable']);
    }

    public function testSinglePredictionIsReturnedForItsOwner(): void
    {
        $predictionId = $this->submit();

        $data = $this->get(GetPredictionHandler::class)
            ->handle(new GetPredictionQuery($predictionId, 1))->data();

        self::assertSame($predictionId, $data['predictionId']);
        self::assertSame(2, $data['predictionData']['homeScore']);
    }

    public function testAnotherParticipantsPredictionIsReportedAsMissing(): void
    {
        $predictionId = $this->submit();

        $this->expectException(EntityNotFoundException::class);
        $this->get(GetPredictionHandler::class)->handle(new GetPredictionQuery($predictionId, 2));
    }

    public function testAnUnknownPredictionIsReportedAsMissing(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(GetPredictionHandler::class)->handle(new GetPredictionQuery('does-not-exist', 1));
    }

    public function testAdminListPaginatesAndFilters(): void
    {
        $this->submit();
        $handler = $this->get(GetAllPredictionsHandler::class);

        $data = $handler->handle(new GetAllPredictionsQuery())->data();
        self::assertCount(1, $data['predictions']);
        self::assertSame(1, $data['pagination']['totalCount']);
        self::assertSame(1, $data['pagination']['totalPages']);

        self::assertCount(1, $handler->handle(new GetAllPredictionsQuery(eventId: 42))->data()['predictions']);
        self::assertCount(0, $handler->handle(new GetAllPredictionsQuery(eventId: 999))->data()['predictions']);
        self::assertCount(0, $handler->handle(new GetAllPredictionsQuery(page: 2))->data()['predictions']);
    }
}
