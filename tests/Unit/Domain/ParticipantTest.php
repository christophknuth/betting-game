<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\Event\ParticipantJoinedGame;
use BettingGame\Domain\Event\ParticipantLeftGame;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use PHPUnit\Framework\TestCase;

final class ParticipantTest extends TestCase
{
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

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantCreated::class, $events[0]);
        $this->assertFalse($events[0]->autoApproved());
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

    public function testApproveParticipant(): void
    {
        $participant = Participant::create(
            1,
            100,
            new DisplayName('Pending User')
        );

        $participant->releaseEvents();
        $participant->approve();

        $this->assertTrue($participant->isActive());
        $this->assertEquals(1, $participant->version());

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantApproved::class, $events[0]);
    }

    public function testApproveAlreadyActiveParticipantThrowsException(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('Participant is already active');

        $participant = Participant::create(
            1,
            100,
            new DisplayName('Active User'),
            true
        );

        $participant->approve();
    }

    public function testJoinGame(): void
    {
        $participant = Participant::create(
            1,
            100,
            new DisplayName('Player'),
            true
        );

        $participant->releaseEvents();
        $participant->joinGame(5, true, 'PAY-123');

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantJoinedGame::class, $events[0]);
        $this->assertEquals(5, $events[0]->bettingGameId());
        $this->assertTrue($events[0]->acceptedTerms());
        $this->assertEquals('PAY-123', $events[0]->paymentReference());
    }

    public function testLeaveGame(): void
    {
        $participant = Participant::create(
            1,
            100,
            new DisplayName('Player'),
            true
        );

        $participant->releaseEvents();
        $participant->leaveGame(5);

        $events = $participant->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ParticipantLeftGame::class, $events[0]);
        $this->assertEquals(5, $events[0]->bettingGameId());
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
