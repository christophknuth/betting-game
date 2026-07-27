<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Application\Command\ApproveParticipantHandler;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Query\GetAllParticipantsHandler;
use BettingGame\Application\Query\GetPendingParticipantsHandler;
use BettingGame\Application\Query\ParticipantReadModel;
use BettingGame\Application\Query\ParticipantReadModelRepositoryInterface;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Presentation\Controller\AdminParticipantController;
use BettingGame\Presentation\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminParticipantControllerTest extends TestCase
{
    private ParticipantRepositoryInterface&MockObject $participantRepo;
    private ParticipantReadModelRepositoryInterface&MockObject $readModelRepo;
    private AdminParticipantController $controller;

    protected function setUp(): void
    {
        $this->participantRepo = $this->createMock(ParticipantRepositoryInterface::class);
        $this->readModelRepo = $this->createMock(ParticipantReadModelRepositoryInterface::class);

        $this->controller = new AdminParticipantController(
            new CreateParticipantHandler($this->participantRepo),
            new ApproveParticipantHandler($this->participantRepo),
            new GetAllParticipantsHandler($this->readModelRepo),
            new GetPendingParticipantsHandler($this->readModelRepo)
        );
    }

    // ---------------------------------------------------------------- reading

    public function testListingParticipantsReturns200(): void
    {
        $this->readModelRepo->method('findAll')->willReturn([$this->readModel()]);

        $response = $this->controller->getAllParticipants($this->request(), []);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $response->data()['participants']);
    }

    public function testFiltersArePassedThrough(): void
    {
        $this->readModelRepo->expects(self::once())
            ->method('findAll')
            ->with('pending_approval', 5)
            ->willReturn([]);

        $this->controller->getAllParticipants(
            $this->request(query: ['status' => 'pending_approval', 'bettingGameId' => '5']),
            []
        );
    }

    public function testPendingListReturns200(): void
    {
        $this->readModelRepo->expects(self::once())
            ->method('findPendingByGame')
            ->with(5)
            ->willReturn([$this->readModel()]);

        $response = $this->controller->getPendingParticipants($this->request(), ['bettingGameId' => '5']);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $response->data()['pendingParticipants']);
    }

    // --------------------------------------------------------------- creating

    public function testCreatingAParticipantReturns202(): void
    {
        $this->participantRepo->method('nextIdentity')->willReturn(7);
        $this->participantRepo->expects(self::once())->method('save');

        $response = $this->controller->createParticipant(
            $this->request(body: ['userId' => 100, 'displayName' => 'Alice']),
            []
        );

        self::assertSame(202, $response->statusCode());
        self::assertSame('7', $response->data()['resourceId']);
    }

    public function testCreatingWithoutAUserIdReturns400(): void
    {
        $this->participantRepo->expects(self::never())->method('save');

        $response = $this->controller->createParticipant(
            $this->request(body: ['displayName' => 'Alice']),
            []
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testCreatingWithANonNumericUserIdReturns400(): void
    {
        $response = $this->controller->createParticipant(
            $this->request(body: ['userId' => 'abc', 'displayName' => 'Alice']),
            []
        );

        self::assertSame(400, $response->statusCode(), '(int) "abc" would silently become 0');
    }

    public function testCreatingWithAnEmptyDisplayNameReturns400(): void
    {
        $response = $this->controller->createParticipant(
            $this->request(body: ['userId' => 100, 'displayName' => '']),
            []
        );

        self::assertSame(400, $response->statusCode());
    }

    // -------------------------------------------------------------- approving

    public function testApprovingReturns202(): void
    {
        $this->participantRepo->method('findParticipant')->willReturn($this->pendingParticipant());
        $this->participantRepo->expects(self::once())->method('save');

        $response = $this->controller->approveParticipant(
            $this->request(body: ['approved' => true]),
            ['participantId' => '1']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testApprovingWithoutTheApprovedFlagReturns400(): void
    {
        $response = $this->controller->approveParticipant(
            $this->request(body: ['notes' => 'looks fine']),
            ['participantId' => '1']
        );

        self::assertSame(400, $response->statusCode());
    }

    public function testApprovingAnUnknownParticipantReturns404(): void
    {
        $this->participantRepo->method('findParticipant')->willReturn(null);

        $response = $this->controller->approveParticipant(
            $this->request(body: ['approved' => true]),
            ['participantId' => '999']
        );

        self::assertSame(404, $response->statusCode());
    }

    public function testApprovingAnAlreadyActiveParticipantReturns409(): void
    {
        $this->participantRepo->method('findParticipant')->willReturn($this->activeParticipant());

        $response = $this->controller->approveParticipant(
            $this->request(body: ['approved' => true]),
            ['participantId' => '1']
        );

        self::assertSame(409, $response->statusCode(), 'a business rule violation is a conflict, not a bad request');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(array $query = [], array $body = []): Request
    {
        return new Request(
            'GET',
            '/',
            [],
            $query,
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private function readModel(): ParticipantReadModel
    {
        return new ParticipantReadModel(
            participantId: 1,
            userId: 100,
            displayName: 'Alice',
            status: 'pending_approval',
            registeredAt: '2026-01-01 00:00:00',
            gamesParticipated: 1,
            totalPoints: 12,
            totalPrizes: 4.5
        );
    }

    private function pendingParticipant(): Participant
    {
        return Participant::create(1, 100, new DisplayName('Alice'));
    }

    private function activeParticipant(): Participant
    {
        return Participant::create(1, 100, new DisplayName('Alice'), true);
    }
}
