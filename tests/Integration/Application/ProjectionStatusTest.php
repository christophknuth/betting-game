<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\CreateBetPeriodCommand;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Infrastructure\Projection\BetPeriodProjector;
use BettingGame\Infrastructure\Projection\TippYearProjector;

/**
 * OPS-04: that what the projection monitor reports is true.
 *
 * The read models are written by the repositories as they save, in the same
 * transaction as the events. `projection_state`, however, used to be touched
 * only by a rebuild - so `GET /admin/projections` computed its lag from a
 * counter the normal write path never moved, and reported a backlog that grew
 * with every command while the data was in fact current.
 *
 * That is a worse failure than it sounds. A monitor that always cries wolf
 * teaches whoever reads it to ignore it, and a projection that genuinely stops
 * being written looks exactly the same. These tests pin down both halves: the
 * counter has to move on an ordinary write, and it still has to be able to
 * report a real backlog.
 */
final class ProjectionStatusTest extends ApplicationTestCase
{
    public function testAnOrdinaryCommandLeavesNoLagBehind(): void
    {
        $this->givenATippYear();

        foreach ($this->projections()->statuses() as $status) {
            self::assertSame(
                0,
                $status->lag,
                sprintf(
                    'Projection %s reports a lag of %d after a plain write, but the ' .
                    'repository wrote its read model in the same transaction.',
                    $status->name,
                    $status->lag
                )
            );
            // `upToDate` is derived, not stored - it is what the endpoint puts
            // on the wire, so it is worth asserting through toArray().
            self::assertTrue(
                $status->toArray()['upToDate'],
                "Projection {$status->name} is not reported as up to date"
            );
        }
    }

    public function testTheCounterKeepsUpAcrossSeveralCommands(): void
    {
        // The symptom that started this: the gap did not appear at once, it
        // grew. One command was not enough to notice, twenty were.
        $tippYearId = $this->givenATippYear();

        for ($month = 1; $month <= 6; $month++) {
            $this->createBetPeriod()->handle(new CreateBetPeriodCommand(
                $tippYearId,
                sprintf('Periode %d', $month),
                sprintf('2026-%02d-01', $month),
                sprintf('2026-%02d-28', $month)
            ));
        }

        foreach ($this->projections()->statuses() as $status) {
            self::assertSame(0, $status->lag, "Projection {$status->name} fell behind");
        }

        // A projection advances when *its own* repository writes, not on every
        // command. So the one that just did the work stands at the head, while
        // the participant projection still sits where its last write left it -
        // with nothing of its kind after that, which is why its lag is 0.
        // Advancing all of them on any write would be the tempting shortcut,
        // and it would hide a projection that genuinely stopped being written.
        self::assertSame(
            $this->eventStore->headPosition(),
            $this->statusOf(BetPeriodProjector::NAME)->lastProcessedPosition
        );
    }

    public function testARealBacklogIsStillReported(): void
    {
        // The counter has to be able to say "behind", or the check above would
        // be satisfied by a monitor that simply always answers zero.
        $this->givenATippYear();

        $this->projectionState->markRunning(TippYearProjector::NAME, 0);

        $behind = $this->statusOf(TippYearProjector::NAME);

        self::assertGreaterThan(0, $behind->lag);
        self::assertFalse($behind->toArray()['upToDate']);
    }

    public function testLagCountsOnlyTheEventsAProjectionConsumes(): void
    {
        // A bet period projection is not behind because a tipp year was
        // created. Without this, every write would raise the alarm everywhere.
        $tippYearId = $this->givenATippYear();

        $this->projectionState->markRunning(BetPeriodProjector::NAME, 0);
        self::assertSame(0, $this->statusOf(BetPeriodProjector::NAME)->lag);

        $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($tippYearId, 'Q1 2026', '2026-01-01', '2026-03-31')
        );
        $this->projectionState->markRunning(BetPeriodProjector::NAME, 0);

        self::assertGreaterThan(0, $this->statusOf(BetPeriodProjector::NAME)->lag);
    }

    public function testARebuildDoesNotLoseTheFailureItRecorded(): void
    {
        // A projection left `failed` by a botched rebuild is half-built. That
        // new writes keep landing does not undo that, so an ordinary command
        // may move the position but must not quietly clear the flag.
        $tippYearId = $this->givenATippYear();

        $this->projectionState->markFailed(BetPeriodProjector::NAME, 'rebuild died halfway');

        $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($tippYearId, 'Q1 2026', '2026-01-01', '2026-03-31')
        );

        $state = $this->projectionState->find(BetPeriodProjector::NAME);

        self::assertNotNull($state);
        self::assertSame('failed', $state['status']);
        self::assertSame('rebuild died halfway', $state['error']);
        self::assertGreaterThan(0, $state['lastProcessedPosition']);
    }

    private function givenATippYear(): int
    {
        $this->db->execute(
            "INSERT INTO user (user_id, username, password_hash, email)
             VALUES (1, 'anna', 'x', 'anna@example.com')"
        );
        $this->participants->save(Participant::create(7, 1, new DisplayName('Anna'), true));

        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );

        self::assertNotNull($year->resourceId);

        $this->addMember()->handle(new AddMemberCommand($year->resourceId, 7));

        return $year->resourceId;
    }

    private function statusOf(string $name): \BettingGame\Application\Projection\ProjectionStatus
    {
        foreach ($this->projections()->statuses() as $status) {
            if ($status->name === $name) {
                return $status;
            }
        }

        self::fail("There is no projection called $name");
    }
}
