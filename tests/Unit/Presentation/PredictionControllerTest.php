<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Application\Command\SubmitPredictionHandler;
use BettingGame\Application\Command\UpdatePredictionHandler;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Application\Query\GetPredictionHandler;
use BettingGame\Application\Query\PredictionReadModel;
use BettingGame\Application\Query\PredictionReadModelRepositoryInterface;
use BettingGame\Domain\Model\Prediction;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\PredictionData;
use BettingGame\Presentation\Controller\PredictionController;
use BettingGame\Presentation\Http\Request;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The handlers are final by design, so they are built for real here and only
 * the repository interfaces are doubled. That keeps the controller and its
 * handler under test together, which is where the interesting wiring sits.
 */
final class PredictionControllerTest extends TestCase
{
    private PredictionRepositoryInterface&MockObject $predictionRepo;
    private ParticipantRepositoryInterface&MockObject $participantRepo;
    private GameEventRepositoryInterface&MockObject $eventRepo;
    private PredictionReadModelRepositoryInterface&MockObject $readModelRepo;
    private PredictionController $controller;

    protected function setUp(): void
    {
        $this->predictionRepo = $this->createMock(PredictionRepositoryInterface::class);
        $this->participantRepo = $this->createMock(ParticipantRepositoryInterface::class);
        $this->eventRepo = $this->createMock(GameEventRepositoryInterface::class);
        $this->readModelRepo = $this->createMock(PredictionReadModelRepositoryInterface::class);

        $this->controller = new PredictionController(
            new SubmitPredictionHandler($this->predictionRepo, $this->participantRepo, $this->eventRepo),
            new UpdatePredictionHandler($this->predictionRepo, $this->eventRepo),
            new GetParticipantPredictionsHandler($this->readModelRepo),
            new GetPredictionHandler($this->readModelRepo)
        );
    }

    // ---------------------------------------------------------------- listing

    public function testListingOwnPredictionsReturns200(): void
    {
        $this->readModelRepo->method('findByParticipant')->willReturn([$this->readModel()]);

        $response = $this->controller->getPredictions($this->request(), ['participantId' => '1']);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $response->data()['predictions']);
        self::assertSame(1, $response->data()['totalCount']);
    }

    public function testListingSomebodyElsesPredictionsIsForbidden(): void
    {
        $response = $this->controller->getPredictions(
            $this->request(authenticatedAs: 2),
            ['participantId' => '1']
        );

        self::assertSame(403, $response->statusCode());
    }

    // ------------------------------------------------------------ single read

    public function testReadingASinglePredictionReturns200(): void
    {
        $this->readModelRepo->method('findById')->willReturn($this->readModel());

        $response = $this->controller->getPrediction(
            $this->request(),
            ['participantId' => '1', 'predictionId' => 'pred-1']
        );

        self::assertSame(200, $response->statusCode());
        self::assertSame('pred-1', $response->data()['predictionId']);
    }

    public function testReadingAnUnknownPredictionReturns404(): void
    {
        $this->readModelRepo->method('findById')->willReturn(null);

        $response = $this->controller->getPrediction(
            $this->request(),
            ['participantId' => '1', 'predictionId' => 'nope']
        );

        self::assertSame(404, $response->statusCode());
    }

    public function testReadingAForeignPredictionReturns404NotForbidden(): void
    {
        // Belongs to participant 2 while participant 1 is asking
        $this->readModelRepo->method('findById')->willReturn($this->readModel(participantId: 2));

        $response = $this->controller->getPrediction(
            $this->request(),
            ['participantId' => '1', 'predictionId' => 'pred-1']
        );

        self::assertSame(404, $response->statusCode(), 'a 403 would confirm the prediction exists');
    }

    public function testReadingSomebodyElsesPredictionIsForbidden(): void
    {
        $response = $this->controller->getPrediction(
            $this->request(authenticatedAs: 2),
            ['participantId' => '1', 'predictionId' => 'pred-1']
        );

        self::assertSame(403, $response->statusCode());
    }

    // ------------------------------------------------------------- submitting

    public function testSubmittingReturns202WithTheCommandResult(): void
    {
        $this->participantRepo->method('exists')->willReturn(true);
        $this->predictionRepo->method('exists')->willReturn(false);
        $this->predictionRepo->method('nextIdentity')->willReturn('pred-new');
        $this->eventRepo->method('getDeadline')->willReturn(new DateTimeImmutable('+1 day'));
        $this->predictionRepo->expects(self::once())->method('save');

        $response = $this->controller->submitPrediction(
            $this->request(body: ['predictionData' => ['homeScore' => 2, 'awayScore' => 1]]),
            ['participantId' => '1', 'eventId' => '42']
        );

        self::assertSame(202, $response->statusCode());
        self::assertSame('accepted', $response->data()['status']);
        self::assertSame('pred-new', $response->data()['resourceId']);
    }

    public function testSubmittingWithoutPredictionDataReturns400(): void
    {
        $this->predictionRepo->expects(self::never())->method('save');

        $response = $this->controller->submitPrediction(
            $this->request(body: []),
            ['participantId' => '1', 'eventId' => '42']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testSubmittingNonObjectPredictionDataReturns400(): void
    {
        $response = $this->controller->submitPrediction(
            $this->request(body: ['predictionData' => 'not-an-object']),
            ['participantId' => '1', 'eventId' => '42']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testSubmittingForSomebodyElseIsForbidden(): void
    {
        $response = $this->controller->submitPrediction(
            $this->request(authenticatedAs: 2, body: ['predictionData' => ['homeScore' => 2]]),
            ['participantId' => '1', 'eventId' => '42']
        );

        self::assertSame(403, $response->statusCode());
    }

    public function testSubmittingForAnUnknownEventReturns400(): void
    {
        $this->participantRepo->method('exists')->willReturn(true);
        $this->predictionRepo->method('exists')->willReturn(false);
        $this->eventRepo->method('getDeadline')->willReturn(null);

        $response = $this->controller->submitPrediction(
            $this->request(body: ['predictionData' => ['homeScore' => 2]]),
            ['participantId' => '1', 'eventId' => '999']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testSubmittingTwiceReturns400(): void
    {
        $this->participantRepo->method('exists')->willReturn(true);
        $this->predictionRepo->method('exists')->willReturn(true);

        $response = $this->controller->submitPrediction(
            $this->request(body: ['predictionData' => ['homeScore' => 2]]),
            ['participantId' => '1', 'eventId' => '42']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testSubmittingAfterTheDeadlineReturns400(): void
    {
        $this->participantRepo->method('exists')->willReturn(true);
        $this->predictionRepo->method('exists')->willReturn(false);
        $this->predictionRepo->method('nextIdentity')->willReturn('pred-new');
        $this->eventRepo->method('getDeadline')->willReturn(new DateTimeImmutable('-1 day'));

        $response = $this->controller->submitPrediction(
            $this->request(body: ['predictionData' => ['homeScore' => 2]]),
            ['participantId' => '1', 'eventId' => '42']
        );

        self::assertSame(400, $response->statusCode());
    }

    // --------------------------------------------------------------- updating

    public function testUpdatingReturns202(): void
    {
        $this->predictionRepo->method('findById')->willReturn($this->prediction());
        $this->eventRepo->method('getDeadline')->willReturn(new DateTimeImmutable('+1 day'));
        $this->predictionRepo->expects(self::once())->method('save');

        $response = $this->controller->updatePrediction(
            $this->request(body: ['predictionData' => ['homeScore' => 3]]),
            ['participantId' => '1', 'predictionId' => 'pred-1']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testUpdatingAnUnknownPredictionReturns400(): void
    {
        $this->predictionRepo->method('findById')->willReturn(null);

        $response = $this->controller->updatePrediction(
            $this->request(body: ['predictionData' => ['homeScore' => 3]]),
            ['participantId' => '1', 'predictionId' => 'nope']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testUpdatingAForeignPredictionReturns400(): void
    {
        $this->predictionRepo->method('findById')->willReturn($this->prediction(participantId: 2));

        $response = $this->controller->updatePrediction(
            $this->request(body: ['predictionData' => ['homeScore' => 3]]),
            ['participantId' => '1', 'predictionId' => 'pred-1']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testUpdatingWithoutPredictionDataReturns400(): void
    {
        $this->predictionRepo->expects(self::never())->method('save');

        $response = $this->controller->updatePrediction(
            $this->request(body: []),
            ['participantId' => '1', 'predictionId' => 'pred-1']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testUpdatingForSomebodyElseIsForbidden(): void
    {
        $response = $this->controller->updatePrediction(
            $this->request(authenticatedAs: 2, body: ['predictionData' => ['homeScore' => 3]]),
            ['participantId' => '1', 'predictionId' => 'pred-1']
        );

        self::assertSame(403, $response->statusCode());
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $body */
    private function request(int $authenticatedAs = 1, array $body = []): Request
    {
        $request = new Request(
            'GET',
            '/',
            [],
            [],
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );

        $request->setAttribute('participant_id', $authenticatedAs);

        return $request;
    }

    private function readModel(int $participantId = 1): PredictionReadModel
    {
        return new PredictionReadModel(
            predictionId: 'pred-1',
            participantId: $participantId,
            eventId: 42,
            eventName: 'Final',
            predictionData: ['homeScore' => 2, 'awayScore' => 1],
            submittedAt: '2026-06-01 10:00:00',
            updatedAt: null,
            deadline: '2099-06-01 19:00:00',
            status: 'submitted',
            isEditable: true
        );
    }

    private function prediction(int $participantId = 1): Prediction
    {
        return Prediction::submit(
            'pred-1',
            new ParticipantId($participantId),
            new EventId(42),
            new PredictionData(['homeScore' => 2]),
            new DateTimeImmutable('+1 day')
        );
    }
}
