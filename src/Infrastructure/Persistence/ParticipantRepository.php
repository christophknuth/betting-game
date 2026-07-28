<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;
use DateTimeImmutable;

final class ParticipantRepository implements ParticipantRepositoryInterface
{
    private const STREAM_PREFIX = 'participant-';

    public function __construct(
        private Db $db,
        private EventStoreInterface $eventStore
    ) {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM participant WHERE participant_id = ?', [$id]);
    }

    public function findParticipant(int $id): ?Participant
    {
        $row = $this->findById($id);

        if ($row === null) {
            return null;
        }

        return Participant::reconstitute(
            id: Row::int($row, 'participant_id'),
            userId: Row::int($row, 'user_id'),
            displayName: new DisplayName(Row::string($row, 'display_name')),
            isActive: Row::bool($row, 'is_active'),
            registeredAt: new DateTimeImmutable(Row::string($row, 'registered_at')),
            version: Row::int($row, 'version')
        );
    }

    public function exists(int $id): bool
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM participant WHERE participant_id = ?',
            [$id]
        );

        return $row !== null && Row::int($row, 'cnt') > 0;
    }

    public function save(Participant $participant): void
    {
        $events = $participant->releaseEvents();
        $expectedVersion = $participant->originalVersion();

        if ($events !== []) {
            $this->eventStore->append(
                self::STREAM_PREFIX . $participant->id(),
                $events,
                $expectedVersion
            );
        }

        // The projection version mirrors the stream version, so the next load
        // knows which version to expect when appending.
        $this->updateProjection($participant, $expectedVersion + count($events));
    }

    public function nextIdentity(): int
    {
        $row = $this->db->fetchOne('SELECT COALESCE(MAX(participant_id), 0) + 1 AS next_id FROM participant');

        return $row !== null ? Row::int($row, 'next_id') : 1;
    }

    private function updateProjection(Participant $participant, int $version): void
    {
        $this->db->execute(
            '
            INSERT INTO participant (participant_id, user_id, display_name, registered_at, is_active, version)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                is_active = VALUES(is_active),
                version = VALUES(version)
            ',
            [
                $participant->id(),
                $participant->userId(),
                $participant->displayName()->value(),
                $participant->registeredAt()->format('Y-m-d H:i:s'),
                $participant->isActive() ? 1 : 0,
                $version,
            ]
        );
    }
}
