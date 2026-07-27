<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;
use DateTimeImmutable;
use PDO;

final class ParticipantRepository implements ParticipantRepositoryInterface
{
    private const STREAM_PREFIX = 'participant-';

    public function __construct(
        private PDO $pdo,
        private EventStoreInterface $eventStore
    ) {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM participant WHERE participant_id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findParticipant(int $id): ?Participant
    {
        $row = $this->findById($id);

        if ($row === null) {
            return null;
        }

        return Participant::reconstitute(
            id: (int) $row['participant_id'],
            userId: (int) $row['user_id'],
            displayName: new DisplayName($row['display_name']),
            isActive: (bool) $row['is_active'],
            registeredAt: new DateTimeImmutable($row['registered_at']),
            version: (int) $row['version']
        );
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) as cnt FROM participant WHERE participant_id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['cnt'] > 0;
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
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(participant_id), 0) + 1 AS next_id FROM participant');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $result['next_id'];
    }

    private function updateProjection(Participant $participant, int $version): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO participant (participant_id, user_id, display_name, registered_at, is_active, version)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                is_active = VALUES(is_active),
                version = VALUES(version)
        ');

        $stmt->execute([
            $participant->id(),
            $participant->userId(),
            $participant->displayName()->value(),
            $participant->registeredAt()->format('Y-m-d H:i:s'),
            $participant->isActive() ? 1 : 0,
            $version,
        ]);
    }
}
