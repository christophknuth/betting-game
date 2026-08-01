<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberHandler;
use BettingGame\Application\Command\AssignBetRowHandler;
use BettingGame\Application\Command\ChangeTippYearStatusCommand;
use BettingGame\Application\Command\ChangeTippYearStatusHandler;
use BettingGame\Application\Command\CreateBetPeriodHandler;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Command\CreateTippYearHandler;
use BettingGame\Application\Command\DistributePayoutHandler;
use BettingGame\Application\Command\RecordDrawHandler;
use BettingGame\Application\Command\RecordDrawWinningsHandler;
use BettingGame\Application\Command\RecordFeePaymentHandler;
use BettingGame\Application\Command\SubmitTicketHandler;
use BettingGame\Application\Query\GetBetPeriodsHandler;
use BettingGame\Application\Query\GetBetRowHandler;
use BettingGame\Application\Query\GetDrawsHandler;
use BettingGame\Application\Query\GetFeesHandler;
use BettingGame\Application\Query\GetMembershipsHandler;
use BettingGame\Application\Query\GetParticipantFeesHandler;
use BettingGame\Application\Query\GetParticipantsHandler;
use BettingGame\Application\Query\GetPayoutShareHandler;
use BettingGame\Application\Query\GetTippYearsHandler;
use BettingGame\Application\Projection\ProjectionManager;
use BettingGame\Domain\ValueObject\TippYearStatus;
use BettingGame\Infrastructure\Persistence\BetPeriodRepository;
use BettingGame\Infrastructure\Persistence\BetRowRepository;
use BettingGame\Infrastructure\Persistence\CommandLogRepository;
use BettingGame\Infrastructure\Persistence\DrawRepository;
use BettingGame\Infrastructure\Persistence\FeeRepository;
use BettingGame\Infrastructure\Persistence\ParticipantRepository;
use BettingGame\Infrastructure\Persistence\ProjectionStateRepository;
use BettingGame\Infrastructure\Persistence\TicketRepository;
use BettingGame\Infrastructure\Persistence\TippYearRepository;
use BettingGame\Infrastructure\Projection\BetPeriodProjector;
use BettingGame\Infrastructure\Projection\BetRowProjector;
use BettingGame\Infrastructure\Projection\DrawProjector;
use BettingGame\Infrastructure\Projection\FeeProjector;
use BettingGame\Infrastructure\Projection\ParticipantProjector;
use BettingGame\Infrastructure\Projection\TicketProjector;
use BettingGame\Infrastructure\Projection\TippYearProjector;
use BettingGame\Tests\Integration\IntegrationTestCase;

/**
 * Handlers wired to real repositories against a real database.
 *
 * Mocking the repositories here would test almost nothing: the interesting
 * parts of these handlers are which rows a query returns, which unique key
 * fires and whether a projection ends up consistent - all of which only a
 * database can answer.
 */
abstract class ApplicationTestCase extends IntegrationTestCase
{
    protected TippYearRepository $tippYears;
    protected BetPeriodRepository $betPeriods;
    protected BetRowRepository $betRows;
    protected TicketRepository $tickets;
    protected DrawRepository $draws;
    protected FeeRepository $fees;
    protected ParticipantRepository $participants;
    protected CommandLogRepository $commandLog;
    protected ProjectionStateRepository $projectionState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectionState = new ProjectionStateRepository($this->db);

        // Every repository records how far its read model is current as it
        // writes, so the state repository has to exist before them.
        $this->tippYears = new TippYearRepository($this->db, $this->eventStore, $this->projectionState);
        $this->betPeriods = new BetPeriodRepository($this->db, $this->eventStore, $this->projectionState);
        $this->betRows = new BetRowRepository($this->db, $this->eventStore, $this->projectionState);
        $this->tickets = new TicketRepository($this->db, $this->eventStore, $this->projectionState);
        $this->draws = new DrawRepository($this->db, $this->eventStore, $this->projectionState);
        $this->fees = new FeeRepository($this->db, $this->eventStore, $this->projectionState);
        $this->participants = new ParticipantRepository($this->db, $this->eventStore, $this->projectionState);
        $this->commandLog = new CommandLogRepository($this->db);
    }

    /**
     * Every projector, in the same set the container wires up.
     */
    protected function projections(): ProjectionManager
    {
        return new ProjectionManager($this->eventStore, $this->projectionState, [
            new ParticipantProjector($this->db),
            new TippYearProjector($this->db),
            new BetPeriodProjector($this->db),
            new BetRowProjector($this->db),
            new TicketProjector($this->db),
            new DrawProjector($this->db),
            new FeeProjector($this->db),
        ]);
    }

    // --- Commands ---

    protected function createTippYear(): CreateTippYearHandler
    {
        return new CreateTippYearHandler($this->tippYears);
    }

    protected function createBetPeriod(): CreateBetPeriodHandler
    {
        return new CreateBetPeriodHandler($this->betPeriods, $this->tippYears);
    }

    protected function addMember(): AddMemberHandler
    {
        return new AddMemberHandler($this->tippYears, $this->participants);
    }

    protected function createParticipant(): CreateParticipantHandler
    {
        return new CreateParticipantHandler($this->participants);
    }

    protected function assignBetRow(): AssignBetRowHandler
    {
        return new AssignBetRowHandler($this->betRows, $this->betPeriods, $this->participants);
    }

    protected function submitTicket(): SubmitTicketHandler
    {
        return new SubmitTicketHandler($this->tickets, $this->tippYears, $this->betRows, $this->fees);
    }

    protected function recordDraw(): RecordDrawHandler
    {
        return new RecordDrawHandler($this->draws, $this->tippYears);
    }

    protected function recordDrawWinnings(): RecordDrawWinningsHandler
    {
        return new RecordDrawWinningsHandler($this->draws, $this->tickets);
    }

    protected function recordFeePayment(): RecordFeePaymentHandler
    {
        return new RecordFeePaymentHandler($this->fees);
    }

    protected function distributePayout(): DistributePayoutHandler
    {
        return new DistributePayoutHandler($this->tippYears, $this->draws);
    }

    protected function changeTippYearStatusHandler(): ChangeTippYearStatusHandler
    {
        return new ChangeTippYearStatusHandler($this->tippYears);
    }

    // --- Queries ---

    protected function getBetRow(): GetBetRowHandler
    {
        return new GetBetRowHandler($this->betRows, $this->betPeriods, $this->tippYears);
    }

    protected function getMemberships(): GetMembershipsHandler
    {
        return new GetMembershipsHandler($this->tippYears, $this->tickets);
    }

    protected function getParticipantFees(): GetParticipantFeesHandler
    {
        return new GetParticipantFeesHandler($this->fees);
    }

    protected function getParticipants(): GetParticipantsHandler
    {
        return new GetParticipantsHandler($this->participants);
    }

    protected function getPayoutShare(): GetPayoutShareHandler
    {
        return new GetPayoutShareHandler($this->tippYears, $this->draws);
    }

    protected function getDraws(): GetDrawsHandler
    {
        return new GetDrawsHandler($this->draws, $this->tippYears, $this->tickets);
    }

    protected function getTippYears(): GetTippYearsHandler
    {
        return new GetTippYearsHandler($this->tippYears);
    }

    protected function getBetPeriods(): GetBetPeriodsHandler
    {
        return new GetBetPeriodsHandler($this->betPeriods, $this->tippYears);
    }

    protected function getFees(): GetFeesHandler
    {
        return new GetFeesHandler($this->fees);
    }

    /**
     * Moves a tipp year into `running`, which is the only status that accepts
     * tickets.
     *
     * Deliberately through the handler rather than the aggregate: that way
     * every test that needs a running year also exercises the command behind
     * B-18, including the rule that only one year runs at a time.
     */
    protected function startTippYear(int $tippYearId): void
    {
        $this->changeTippYearStatus($tippYearId, TippYearStatus::RUNNING);
    }

    protected function closeTippYear(int $tippYearId): void
    {
        $this->changeTippYearStatus($tippYearId, TippYearStatus::CLOSED);
    }

    protected function changeTippYearStatus(int $tippYearId, string $status): void
    {
        $this->changeTippYearStatusHandler()
            ->handle(new ChangeTippYearStatusCommand($tippYearId, $status));
    }
}
