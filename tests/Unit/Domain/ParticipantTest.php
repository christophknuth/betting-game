<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\Event\ParticipantRenamed;
use BettingGame\Domain\Event\ParticipantStatusChanged;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use PHPUnit\Framework\TestCase;

final class ParticipantTest extends TestCase
{
    /** A participant as the administrator creates them: active, with no events pending. */
    private function active(string $displayName): Participant
    {
        $participant = Participant::create(1, null, new DisplayName($displayName), true);
        $participant->releaseEvents();

        return $participant;
    }

    public function testCreateParticipant(): void
    {
        $participant = Participant::create(
            1,
            100,
            new DisplayName('Max Mustermann')
        );

        $this->assertEquals(1, $participant->id());
        $this->assertEquals(100, $participant->userId());
        $this->assertEquals('Max Mustermann', $participant->displayName()->value());
        $this->assertFalse($participant->isActive());
        $this->assertTrue($participant->status()->isPending());

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantCreated::class, $events[0]);
        $this->assertFalse($events[0]->autoApproved());
        $this->assertNull($events[0]->keycloakSubject(), 'nobody registered themselves here');
    }

    public function testRegisteringLeavesAPendingParticipantForTheAccount(): void
    {
        // E1-01: the account is what the token said, and the participant is a
        // request until an administrator says otherwise.
        $participant = Participant::register(7, 'a5f0-sub', new DisplayName('Erika Mustermann'));

        $this->assertTrue($participant->status()->isPending());
        $this->assertFalse($participant->isActive());
        $this->assertSame('a5f0-sub', $participant->keycloakSubject());

        $events = $participant->releaseEvents();
        $this->assertInstanceOf(ParticipantCreated::class, $events[0]);
        $this->assertSame('a5f0-sub', $events[0]->toArray()['keycloak_subject']);
        $this->assertFalse($events[0]->autoApproved());
    }

    public function testARegistrationWithoutAnAccountIsRefused(): void
    {
        $this->expectException(BusinessRuleViolationException::class);

        Participant::register(7, '  ', new DisplayName('Erika Mustermann'));
    }

    public function testCreateParticipantWithAutoApprove(): void
    {
        $participant = Participant::create(
            1,
            100,
            new DisplayName('Auto User'),
            true
        );

        $this->assertTrue($participant->isActive());

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantCreated::class, $events[0]);
        $this->assertTrue($events[0]->autoApproved());
    }

    public function testSayingYesToARegistrationIsRecordedAsAnApproval(): void
    {
        // E1-01: same command as B-25's status change, different fact - and an
        // audit trail that cannot tell an approval from a reactivation has lost
        // the more interesting of the two.
        $participant = Participant::register(1, 'a5f0-sub', new DisplayName('Pending User'));
        $participant->releaseEvents();

        $participant->changeStatus(true);

        $this->assertTrue($participant->isActive());
        $this->assertEquals(1, $participant->version());

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantApproved::class, $events[0]);
    }

    public function testARefusedRegistrationBecomesInactive(): void
    {
        $participant = Participant::register(1, 'a5f0-sub', new DisplayName('Pending User'));
        $participant->releaseEvents();

        // Pending is not inactive, so this is a change and not a no-op - the
        // administrator has decided, and the answer was no.
        $participant->changeStatus(false);

        $this->assertFalse($participant->isActive());
        $this->assertFalse($participant->status()->isPending());
        $this->assertInstanceOf(ParticipantStatusChanged::class, $participant->releaseEvents()[0]);
    }


    public function testRenamingRecordsBothNames(): void
    {
        // B-25: the previous name travels with the event. A rename changes who
        // a reader thinks a fee or a payout share belonged to, and the history
        // has to be able to say what the name was at the time.
        $participant = $this->active('Erika Musterman');
        $participant->rename(new DisplayName('Erika Mustermann'));

        $this->assertSame('Erika Mustermann', $participant->displayName()->value());

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantRenamed::class, $events[0]);
        $this->assertSame(
            [
                'participant_id' => '1',
                'previous_display_name' => 'Erika Musterman',
                'display_name' => 'Erika Mustermann',
            ],
            $events[0]->toArray()
        );
    }

    public function testRenamingToTheSameNameIsRejected(): void
    {
        // An event that describes no change does not belong in the history.
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('identical to the current one');

        $this->active('Erika Mustermann')->rename(new DisplayName('Erika Mustermann'));
    }

    public function testDeactivatingAndReactivatingRecordTheNewState(): void
    {
        $participant = $this->active('Erika Mustermann');

        $participant->changeStatus(false);
        $this->assertFalse($participant->isActive());

        $events = $participant->releaseEvents();
        $this->assertInstanceOf(ParticipantStatusChanged::class, $events[0]);
        $this->assertFalse($events[0]->toArray()['is_active']);

        $participant->changeStatus(true);
        $this->assertTrue($participant->isActive());
        $this->assertTrue($participant->releaseEvents()[0]->toArray()['is_active']);
    }

    public function testSettingTheStatusThatIsAlreadySetIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('already active');

        $this->active('Erika Mustermann')->changeStatus(true);
    }

    public function testReleaseEventsClears(): void
    {
        $participant = Participant::create(
            1,
            100,
            new DisplayName('Test User')
        );

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);

        $events2 = $participant->releaseEvents();
        $this->assertCount(0, $events2);
    }
}
