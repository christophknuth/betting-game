<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberHandler;
use BettingGame\Application\Command\AssignBetRowHandler;
use BettingGame\Application\Command\CreateBetPeriodHandler;
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
use BettingGame\Application\Query\GetPayoutShareHandler;
use BettingGame\Application\Query\GetTippYearsHandler;
use BettingGame\Infrastructure\Persistence\BetPeriodRepository;
use BettingGame\Infrastructure\Persistence\BetRowRepository;
use BettingGame\Infrastructure\Persistence\DrawRepository;
use BettingGame\Infrastructure\Persistence\FeeRepository;
use BettingGame\Infrastructure\Persistence\ParticipantRepository;
use BettingGame\Infrastructure\Persistence\TicketRepository;
use BettingGame\Infrastructure\Persistence\TippYearRepository;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->tippYears = new TippYearRepository($this->db, $this->eventStore);
        $this->betPeriods = new BetPeriodRepository($this->db, $this->eventStore);
        $this->betRows = new BetRowRepository($this->db, $this->eventStore);
        $this->tickets = new TicketRepository($this->db, $this->eventStore);
        $this->draws = new DrawRepository($this->db, $this->eventStore);
        $this->fees = new FeeRepository($this->db, $this->eventStore);
        $this->participants = new ParticipantRepository($this->db, $this->eventStore);
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
     * tickets. There is no command for it yet - the lifecycle transitions are
     * not part of the base version's endpoints.
     */
    protected function startTippYear(int $tippYearId): void
    {
        $year = $this->tippYears->find($tippYearId);
        self::assertNotNull($year);
        $year->start();
        $this->tippYears->save($year);
    }

    protected function closeTippYear(int $tippYearId): void
    {
        $year = $this->tippYears->find($tippYearId);
        self::assertNotNull($year);
        $year->close();
        $this->tippYears->save($year);
    }
}
