<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Application\Command\JoinGameHandler;
use BettingGame\Application\Command\LeaveGameHandler;
use BettingGame\Application\Query\GetParticipationsHandler;
use BettingGame\Application\Query\ParticipationReadModel;
use BettingGame\Application\Query\ParticipationReadModelRepositoryInterface;
use BettingGame\Domain\Model\BettingGame;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Presentation\Controller\ParticipationController;
use BettingGame\Presentation\Http\Request;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ParticipationControllerTest extends TestCase
{
    private ParticipantRepositoryInterface&MockObject $participantRepo;
    private BettingGameRepositoryInterface&MockObject $gameRepo;
    private ParticipationReadModelRepositoryInterface&MockObject $readModelRepo;
    private ParticipationController $controller;

    protected function setUp(): void
    {
        $this->participantRepo = $this->createMock(ParticipantRepositoryInterface::class);
        $this->gameRepo = $this->createMock(BettingGameRepositoryInterface::class);
        $this->readModelRepo = $this->createMock(ParticipationReadModelRepositoryInterface::class);

        $this->controller = new ParticipationController(
            new JoinGameHandler($this->participantRepo, $this->gameRepo),
            new LeaveGameHandler($this->participantRepo),
            new GetParticipationsHandler($this->readModelRepo)
        );
    }

    public function testListingParticipationsReturns200(): void
    {
        $this->readModelRepo->method('findByParticipant')->willReturn([$this->readModel()]);

        $response = $this->controller->getParticipations($this->request(), ['participantId' => '1']);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $response->data()['participations']);
    }

    public function testListingForAnotherParticipantIsForbidden(): void
    {
        $response = $this->controller->getParticipations(
            $this->request(authenticatedAs: 2),
            ['participantId' => '1']
        );

        self::assertSame(403, $response->statusCode());
    }

    public function testJoiningReturns202(): void
    {
        $this->participantRepo->method('exists')->willReturn(true);
        $this->participantRepo->method('findParticipant')->willReturn($this->participant());
        $this->gameRepo->method('findById')->willReturn($this->game());
        $this->participantRepo->expects(self::once())->method('save');

        $response = $this->controller->joinGame(
            $this->request(body: ['acceptTerms' => true, 'paymentReference' => 'PAY-1']),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testJoiningWithoutAcceptTermsReturns400(): void
    {
        $this->participantRepo->expects(self::never())->method('save');

        $response = $this->controller->joinGame(
            $this->request(body: []),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testJoiningWithANonBooleanAcceptTermsReturns400(): void
    {
        $response = $this->controller->joinGame(
            $this->request(body: ['acceptTerms' => 'yes']),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testJoiningForAnotherParticipantIsForbidden(): void
    {
        $response = $this->controller->joinGame(
            $this->request(authenticatedAs: 2, body: ['acceptTerms' => true]),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(403, $response->statusCode());
    }

    public function testJoiningAnUnknownGameReturns400(): void
    {
        $this->participantRepo->method('exists')->willReturn(true);
        $this->gameRepo->method('findById')->willReturn(null);

        $response = $this->controller->joinGame(
            $this->request(body: ['acceptTerms' => true]),
            ['participantId' => '1', 'bettingGameId' => '999']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testLeavingReturns202(): void
    {
        $this->participantRepo->method('findParticipant')->willReturn($this->participant());
        $this->participantRepo->expects(self::once())->method('save');

        $response = $this->controller->leaveGame(
            $this->request(),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testLeavingForAnotherParticipantIsForbidden(): void
    {
        $response = $this->controller->leaveGame(
            $this->request(authenticatedAs: 2),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(403, $response->statusCode());
    }

    /** @param array<string, mixed> $body */
    private function request(int $authenticatedAs = 1, array $body = []): Request
    {
        $request = new Request(
            'POST',
            '/',
            [],
            [],
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );

        $request->setAttribute('participant_id', $authenticatedAs);

        return $request;
    }

    private function readModel(): ParticipationReadModel
    {
        return new ParticipationReadModel(
            participantId: 1,
            bettingGameId: 5,
            bettingGameName: 'Test Cup',
            gameType: 'Football',
            status: 'active',
            joinedAt: '2026-01-01 00:00:00',
            startDate: '2026-01-01 00:00:00',
            endDate: '2026-12-31 00:00:00'
        );
    }

    private function participant(): Participant
    {
        return Participant::create(1, 100, new DisplayName('Alice'), true);
    }

    private function game(): BettingGame
    {
        return BettingGame::create(
            5,
            'Test Cup',
            'A cup',
            1,
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31')
        );
    }
}
