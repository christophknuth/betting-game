<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\ChangeParticipantStatusCommand;
use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Application\Command\RenameParticipantCommand;
use BettingGame\Application\Query\GetParticipantsQuery;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Support\Row;

/**
 * B-25: the administrator corrects a name, or records that somebody has left.
 *
 * Both write an event and are therefore the only two ways the roster changes
 * after a participant exists. There is deliberately no third: deleting would
 * take memberships, fees and payout shares of played years with it, or leave
 * them pointing at nothing.
 */
final class EditParticipantTest extends ApplicationTestCase
{
    private function givenParticipantNamed(string $displayName): int
    {
        $created = $this->createParticipant()->handle(new CreateParticipantCommand($displayName));
        self::assertNotNull($created->resourceId);

        return $created->resourceId;
    }

    public function testRenamingCorrectsTheNameEverywhereItIsRead(): void
    {
        $participantId = $this->givenParticipantNamed('Erika Musterman');

        $this->renameParticipant()->handle(
            new RenameParticipantCommand($participantId, 'Erika Mustermann')
        );

        $row = $this->participants->findById($participantId);
        self::assertNotNull($row);
        self::assertSame('Erika Mustermann', Row::string($row, 'display_name'));
    }

    public function testTheRenameStandsInTheEventLogWithTheOldName(): void
    {
        $participantId = $this->givenParticipantNamed('Erika Musterman');

        $this->renameParticipant()->handle(
            new RenameParticipantCommand($participantId, 'Erika Mustermann')
        );

        $events = $this->eventStore->recordsOf('participant-' . $participantId);

        self::assertCount(2, $events, 'created, then renamed');
        self::assertSame('participant.renamed', $events[1]->event->eventType());

        $payload = $events[1]->event->toArray();
        self::assertSame('Erika Musterman', $payload['previous_display_name']);
        self::assertSame('Erika Mustermann', $payload['display_name']);
    }

    public function testARebuildProducesTheCorrectedNameAndStatus(): void
    {
        // The projector and the write path have to agree, or a rebuild would
        // quietly restore the typo and put somebody back into play.
        $participantId = $this->givenParticipantNamed('Erika Musterman');

        $this->renameParticipant()->handle(
            new RenameParticipantCommand($participantId, 'Erika Mustermann')
        );
        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($participantId, false)
        );

        $this->projections()->rebuild('participant_read_model');

        $row = $this->participants->findById($participantId);
        self::assertNotNull($row);
        self::assertSame('Erika Mustermann', Row::string($row, 'display_name'));
        self::assertFalse(Row::bool($row, 'is_active'));
    }

    public function testRenamingAnUnknownParticipantIsRefused(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->renameParticipant()->handle(new RenameParticipantCommand(9999, 'Niemand'));
    }

    public function testRenamingToTheSameNameIsRefused(): void
    {
        $participantId = $this->givenParticipantNamed('Erika Mustermann');

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/identical to the current one/');

        $this->renameParticipant()->handle(
            new RenameParticipantCommand($participantId, 'Erika Mustermann')
        );
    }

    public function testAnInactiveParticipantIsNotOfferedToAPicker(): void
    {
        $staying = $this->givenParticipantNamed('Anna Bleibt');
        $leaving = $this->givenParticipantNamed('Bernd Geht');

        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($leaving, false)
        );

        $all = $this->getParticipants()->handle(new GetParticipantsQuery())->toArray();
        $active = $this->getParticipants()->handle(new GetParticipantsQuery(true))->toArray();

        self::assertSame([$staying, $leaving], array_column($all['participants'], 'participantId'));
        self::assertSame(
            [$staying],
            array_column($active['participants'], 'participantId'),
            'the roster shows everybody, a picker only the ones still playing'
        );
    }

    public function testAnInactiveParticipantCannotJoinATippYear(): void
    {
        // The rule that makes "inactive" mean something. Without it the flag
        // would be a badge that changes colour.
        $participantId = $this->givenParticipantNamed('Bernd Geht');
        $tippYearId = $this->givenARunningTippYear();

        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($participantId, false)
        );

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/inactive and cannot join/');

        $this->addMember()->handle(new AddMemberCommand($tippYearId, $participantId));
    }

    public function testSomebodyWhoComesBackCanJoinAgain(): void
    {
        $participantId = $this->givenParticipantNamed('Bernd Kehrt Zurück');
        $tippYearId = $this->givenARunningTippYear();

        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($participantId, false)
        );
        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($participantId, true)
        );

        $this->addMember()->handle(new AddMemberCommand($tippYearId, $participantId));

        self::assertSame([$participantId], $this->tippYears->memberIds($tippYearId));
    }

    public function testSettingTheStatusThatIsAlreadySetIsRefused(): void
    {
        // An event that describes no change does not belong in the history -
        // the same rule B-18 applies to a tipp year's status.
        $participantId = $this->givenParticipantNamed('Anna Bleibt');

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already active/');

        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($participantId, true)
        );
    }

    private function givenARunningTippYear(): int
    {
        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        self::assertNotNull($year->resourceId);

        $this->startTippYear($year->resourceId);

        return $year->resourceId;
    }
}
