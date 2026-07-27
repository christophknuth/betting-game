<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Application\Command\AwardScoreHandler;
use BettingGame\Application\Command\CalculateScoresHandler;
use BettingGame\Application\Command\RecordResultHandler;
use BettingGame\Application\Command\UpdateResultHandler;
use BettingGame\Domain\Model\Result;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\ResultRepositoryInterface;
use BettingGame\Presentation\Controller\AdminResultController;
use BettingGame\Presentation\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminResultControllerTest extends TestCase
{
    private ResultRepositoryInterface&MockObject $resultRepo;
    private GameEventRepositoryInterface&MockObject $eventRepo;
    private ParticipantRepositoryInterface&MockObject $participantRepo;
    private AdminResultController $controller;

    protected function setUp(): void
    {
        $this->resultRepo = $this->createMock(ResultRepositoryInterface::class);
        $this->eventRepo = $this->createMock(GameEventRepositoryInterface::class);
        $this->participantRepo = $this->createMock(ParticipantRepositoryInterface::class);

        $this->controller = new AdminResultController(
            new RecordResultHandler($this->resultRepo, $this->eventRepo),
            new UpdateResultHandler($this->resultRepo),
            new CalculateScoresHandler($this->eventRepo),
            new AwardScoreHandler($this->participantRepo)
        );
    }

    // ---------------------------------------------------------------- results

    public function testRecordingAResultReturns202(): void
    {
        $this->eventRepo->method('findById')->willReturn(['event_id' => 42]);
        $this->resultRepo->method('nextIdentity')->willReturn(1);
        $this->resultRepo->expects(self::once())->method('save');

        $response = $this->controller->recordResult(
            $this->request(['resultData' => ['homeScore' => 3], 'source' => 'feed']),
            ['eventId' => '42']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testRecordingWithoutResultDataReturns400(): void
    {
        $this->resultRepo->expects(self::never())->method('save');

        $response = $this->controller->recordResult($this->request([]), ['eventId' => '42']);

        self::assertSame(400, $response->statusCode());
    }

    public function testRecordingWithANonObjectSourceReturns400(): void
    {
        $response = $this->controller->recordResult(
            $this->request(['resultData' => ['homeScore' => 3], 'source' => 42]),
            ['eventId' => '42']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testRecordingForAnUnknownEventReturns404(): void
    {
        $this->eventRepo->method('findById')->willReturn(null);

        $response = $this->controller->recordResult(
            $this->request(['resultData' => ['homeScore' => 3]]),
            ['eventId' => '999']
        );

        self::assertSame(404, $response->statusCode());
    }

    public function testUpdatingAResultReturns202(): void
    {
        $this->resultRepo->method('findByEventId')->willReturn(Result::record(1, 42, ['homeScore' => 3]));
        $this->resultRepo->expects(self::once())->method('save');

        $response = $this->controller->updateResult(
            $this->request(['resultData' => ['homeScore' => 4], 'reason' => 'correction']),
            ['eventId' => '42']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testUpdatingAResultThatDoesNotExistReturns404(): void
    {
        $this->resultRepo->method('findByEventId')->willReturn(null);

        $response = $this->controller->updateResult(
            $this->request(['resultData' => ['homeScore' => 4]]),
            ['eventId' => '42']
        );

        self::assertSame(404, $response->statusCode());
    }

    // ----------------------------------------------------------------- scores

    public function testCalculatingScoresReturns202(): void
    {
        $this->eventRepo->method('findById')->willReturn(['event_id' => 42]);

        $response = $this->controller->calculateScores($this->request([]), ['eventId' => '42']);

        self::assertSame(202, $response->statusCode());
    }

    public function testCalculatingScoresForAnUnknownEventReturns404(): void
    {
        $this->eventRepo->method('findById')->willReturn(null);

        $response = $this->controller->calculateScores($this->request([]), ['eventId' => '999']);

        self::assertSame(404, $response->statusCode());
    }

    public function testAwardingAScoreReturns202(): void
    {
        $this->participantRepo->method('exists')->willReturn(true);

        $response = $this->controller->awardScore(
            $this->request(['bettingGameId' => 5, 'eventId' => 42, 'pointsEarned' => 10]),
            ['participantId' => '1']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testAwardingWithoutPointsOrPrizeReturns400(): void
    {
        $response = $this->controller->awardScore(
            $this->request(['bettingGameId' => 5, 'eventId' => 42]),
            ['participantId' => '1']
        );

        self::assertSame(400, $response->statusCode());
        self::assertStringContainsString('pointsEarned', $response->data()['message']);
    }

    public function testAwardingWithoutGameOrEventReturns400(): void
    {
        $response = $this->controller->awardScore(
            $this->request(['pointsEarned' => 10]),
            ['participantId' => '1']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testAwardingWithANonNumericPrizeReturns400(): void
    {
        $response = $this->controller->awardScore(
            $this->request(['bettingGameId' => 5, 'eventId' => 42, 'prizeAmount' => 'lots']),
            ['participantId' => '1']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testAwardingToAnUnknownParticipantReturns404(): void
    {
        $this->participantRepo->method('exists')->willReturn(false);

        $response = $this->controller->awardScore(
            $this->request(['bettingGameId' => 5, 'eventId' => 42, 'pointsEarned' => 10]),
            ['participantId' => '999']
        );

        self::assertSame(404, $response->statusCode());
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): Request
    {
        return new Request(
            'POST',
            '/',
            [],
            [],
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );
    }
}
