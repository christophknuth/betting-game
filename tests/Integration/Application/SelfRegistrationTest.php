<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\ChangeParticipantStatusCommand;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Application\Command\RegisterParticipantCommand;
use BettingGame\Application\Query\GetMyRegistrationQuery;
use BettingGame\Application\Query\GetParticipantsQuery;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\ParticipantStatus;
use BettingGame\Support\Row;

/**
 * E1-01: somebody signs themselves up, an administrator decides.
 *
 * The registration is a request, not a membership. What makes it self-service
 * is the account it carries: the same login is recognised on the next request
 * without anybody entering an id into the realm, which is what B-21 still
 * required.
 */
final class SelfRegistrationTest extends ApplicationTestCase
{
    private const SUBJECT = '3f1c8b64-0a1e-4f2b-9a77-account';

    public function testARegistrationWaitsForApproval(): void
    {
        $result = $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );
        self::assertNotNull($result->resourceId);

        $row = $this->participants->findById($result->resourceId);

        self::assertNotNull($row);
        self::assertSame('Erika Mustermann', Row::string($row, 'display_name'));
        self::assertSame(ParticipantStatus::PENDING, Row::string($row, 'status'));
        self::assertSame(self::SUBJECT, Row::string($row, 'keycloak_subject'));
    }

    public function testTheRegistrationStandsInAnEventAndSurvivesARebuild(): void
    {
        $result = $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );
        self::assertNotNull($result->resourceId);

        $events = $this->eventStore->recordsOf('participant-' . $result->resourceId);
        self::assertCount(1, $events);
        self::assertSame('participant.created', $events[0]->event->eventType());
        self::assertSame(self::SUBJECT, $events[0]->event->toArray()['keycloak_subject']);

        // The account link has to come back from the log too - without it the
        // person would be signed in and unrecognised after every rebuild.
        $this->projections()->rebuild('participant_read_model');

        $found = $this->participants->findByKeycloakSubject(self::SUBJECT);
        self::assertNotNull($found);
        self::assertTrue($found->status()->isPending());
    }

    public function testTheSameAccountCannotRegisterTwice(): void
    {
        $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already registered and is waiting/');

        $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Nochmal')
        );
    }

    public function testAnApprovedAccountIsToldSoRatherThanRegisteredAgain(): void
    {
        $result = $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );
        self::assertNotNull($result->resourceId);

        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($result->resourceId, true)
        );

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already belongs to a participant/');

        $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );
    }

    public function testSayingYesIsRecordedAsAnApprovalAndLetsThemPlay(): void
    {
        $result = $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );
        self::assertNotNull($result->resourceId);
        $participantId = $result->resourceId;

        $tippYearId = $this->givenARunningTippYear();

        $this->changeParticipantStatus()->handle(
            new ChangeParticipantStatusCommand($participantId, true)
        );

        $events = $this->eventStore->recordsOf('participant-' . $participantId);
        self::assertSame('participant.approved', $events[1]->event->eventType());

        $this->addMember()->handle(new AddMemberCommand($tippYearId, $participantId));
        self::assertSame([$participantId], $this->tippYears->memberIds($tippYearId));
    }

    public function testAPendingRegistrationCannotJoinATippYear(): void
    {
        $result = $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );
        self::assertNotNull($result->resourceId);

        $tippYearId = $this->givenARunningTippYear();

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/is pending and cannot join/');

        $this->addMember()->handle(new AddMemberCommand($tippYearId, $result->resourceId));
    }

    public function testTheAdministratorSeesWhatIsWaiting(): void
    {
        $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );

        $pending = $this->getParticipants()
            ->handle(new GetParticipantsQuery(ParticipantStatus::PENDING))
            ->toArray();

        self::assertCount(1, $pending['participants']);
        self::assertSame('Erika Mustermann', $pending['participants'][0]['displayName']);
        self::assertTrue(
            $pending['participants'][0]['selfRegistered'],
            'the roster says who asked to join rather than who was entered'
        );
    }

    public function testAnAccountAsksWhatBecameOfItsRegistration(): void
    {
        $before = $this->myRegistration()
            ->handle(new GetMyRegistrationQuery(self::SUBJECT))
            ->toArray();

        // Not a 404: asking is legitimate for anyone signed in, and "you have
        // not registered" is the answer.
        self::assertFalse($before['registered']);

        $this->registerParticipant()->handle(
            new RegisterParticipantCommand(self::SUBJECT, 'Erika Mustermann')
        );

        $after = $this->myRegistration()
            ->handle(new GetMyRegistrationQuery(self::SUBJECT))
            ->toArray();

        self::assertTrue($after['registered']);
        self::assertSame(ParticipantStatus::PENDING, $after['status']);
        self::assertSame('Erika Mustermann', $after['displayName']);
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
