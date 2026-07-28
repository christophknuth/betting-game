<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Infrastructure\Persistence\ParticipantRepository;
use BettingGame\Support\Row;

/**
 * Covers the repository after it moved onto EventSourcedRepository and the
 * split insert/update write - the participant projection behaved differently
 * before and nothing else exercises it.
 */
final class ParticipantRepositoryTest extends IntegrationTestCase
{
    private ParticipantRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ParticipantRepository($this->db, $this->eventStore);

        $this->db->execute(
            "
            INSERT INTO user (user_id, username, password_hash, email) VALUES
                (1, 'anna', 'x', 'anna@example.com'),
                (2, 'ben', 'x', 'ben@example.com')
            "
        );
    }

    public function testSavingRoundTrips(): void
    {
        $participant = Participant::create(1, 1, new DisplayName('Anna'));
        $this->repository->save($participant);

        $loaded = $this->repository->findParticipant(1);

        self::assertNotNull($loaded);
        self::assertSame('Anna', $loaded->displayName()->value());
        self::assertFalse($loaded->isActive());
        self::assertSame(1, $this->eventStore->getStreamVersion('participant-1'));
    }

    public function testApprovingUpdatesTheProjection(): void
    {
        $participant = Participant::create(1, 1, new DisplayName('Anna'));
        $this->repository->save($participant);

        $loaded = $this->repository->findParticipant(1);
        self::assertNotNull($loaded);
        $loaded->approve();
        $this->repository->save($loaded);

        $reloaded = $this->repository->findParticipant(1);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive());
        self::assertSame(2, $this->eventStore->getStreamVersion('participant-1'));
    }

    public function testTheSameInstanceCanBeSavedTwice(): void
    {
        $participant = Participant::create(1, 1, new DisplayName('Anna'));
        $this->repository->save($participant);

        // Without markCommitted this fails the optimistic-locking check even
        // though nobody else touched the stream.
        $participant->approve();
        $this->repository->save($participant);

        self::assertSame(2, $this->eventStore->getStreamVersion('participant-1'));
    }

    public function testOneParticipantPerUserAccount(): void
    {
        $this->repository->save(Participant::create(1, 1, new DisplayName('Anna')));

        $this->expectException(DuplicateEntryException::class);
        $this->expectExceptionMessageMatches('/uk_user/');
        $this->repository->save(Participant::create(2, 1, new DisplayName('Anna again')));
    }

    public function testExistsAndFindById(): void
    {
        $this->repository->save(Participant::create(1, 1, new DisplayName('Anna')));

        self::assertTrue($this->repository->exists(1));
        self::assertFalse($this->repository->exists(99));

        $row = $this->repository->findById(1);
        self::assertNotNull($row);
        self::assertSame('Anna', Row::string($row, 'display_name'));
        self::assertNull($this->repository->findById(99));
    }

    public function testNextIdentityFollowsTheMaximum(): void
    {
        self::assertSame(1, $this->repository->nextIdentity());

        $this->repository->save(Participant::create(1, 1, new DisplayName('Anna')));

        self::assertSame(2, $this->repository->nextIdentity());
    }
}
