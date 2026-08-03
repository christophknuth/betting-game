<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Query\GetParticipantsQuery;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\ValueObject\ParticipantStatus;
use BettingGame\Support\Row;

/**
 * B-21: the administrator creates a participant.
 *
 * The point of the command - rather than the INSERT by hand this replaces - is
 * that the row stands in an event afterwards. QUICKSTART.md warned about
 * exactly that: a hand-written participant survives until the first projection
 * rebuild and then quietly disappears.
 */
final class CreateParticipantTest extends ApplicationTestCase
{
    public function testCreatesAnActiveParticipant(): void
    {
        $result = $this->createParticipant()->handle(new CreateParticipantCommand('Erika Mustermann'));

        $row = $this->participants->findById($result->resourceId ?? 0);

        $this->assertNotNull($row);
        $this->assertSame('Erika Mustermann', Row::string($row, 'display_name'));
        $this->assertSame(
            ParticipantStatus::ACTIVE,
            Row::string($row, 'status'),
            'What an admin enters counts as approved'
        );
        $this->assertNull(
            Row::nullableInt($row, 'user_id'),
            'No account is linked: identity comes from Keycloak, and `user` predates it'
        );
    }

    public function testTheParticipantExistsAsAnEventNotJustARow(): void
    {
        $result = $this->createParticipant()->handle(new CreateParticipantCommand('Max Mustermann'));
        $participantId = $result->resourceId ?? 0;

        $events = $this->eventStore->recordsOf('participant-' . $participantId);

        $this->assertCount(1, $events);
        $this->assertSame('participant.created', $events[0]->event->eventType());

        // The whole reason this command exists: a rebuild has to bring the
        // participant back, which a hand-written row could never do.
        $this->projections()->rebuild('participant_read_model');

        $this->assertNotNull($this->participants->findById($participantId));
    }

    public function testTrimsTheDisplayName(): void
    {
        $result = $this->createParticipant()->handle(new CreateParticipantCommand('  Anna Beispiel  '));

        $row = $this->participants->findById($result->resourceId ?? 0);

        $this->assertNotNull($row);
        $this->assertSame('Anna Beispiel', Row::string($row, 'display_name'));
    }

    public function testRejectsATooShortDisplayName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createParticipant()->handle(new CreateParticipantCommand('A'));
    }

    public function testRejectsABlankDisplayName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createParticipant()->handle(new CreateParticipantCommand('   '));
    }

    public function testListsParticipantsByName(): void
    {
        $this->createParticipant()->handle(new CreateParticipantCommand('Zora Zuletzt'));
        $this->createParticipant()->handle(new CreateParticipantCommand('Anton Zuerst'));

        $result = $this->getParticipants()->handle(new GetParticipantsQuery())->toArray();

        $names = array_column($result['participants'], 'displayName');

        $this->assertContains('Anton Zuerst', $names);
        $this->assertContains('Zora Zuletzt', $names);
        $this->assertLessThan(
            array_search('Zora Zuletzt', $names, true),
            array_search('Anton Zuerst', $names, true),
            'Sorted by name, because that is what a reader scans'
        );
    }
}
