<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\Event\ParticipantJoinedGame;
use BettingGame\Domain\Event\ParticipantLeftGame;
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
        $this->projectParticipations($events);
    }

    /**
     * Participation lives on the Participant aggregate but has its own read
     * table, so the join/leave events have to be applied to it here. Without
     * this, game_participation would never receive a row.
     *
     * @param list<DomainEvent> $events
     */
    private function projectParticipations(array $events): void
    {
        foreach ($events as $event) {
            if ($event instanceof ParticipantJoinedGame) {
                $this->db->execute(
                    '
                    INSERT INTO game_participation (participant_id, betting_game_id, joined_at, status)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        joined_at = VALUES(joined_at),
                        left_at = NULL
                    ',
                    [
                        (int) $event->aggregateId(),
                        $event->bettingGameId(),
                        $event->occurredAt()->format('Y-m-d H:i:s'),
                        'pending_approval',
                    ]
                );

                continue;
            }

            if ($event instanceof ParticipantLeftGame) {
                $this->db->execute(
                    '
                    UPDATE game_participation
                    SET status = "ended", left_at = ?
                    WHERE participant_id = ? AND betting_game_id = ?
                    ',
                    [
                        $event->occurredAt()->format('Y-m-d H:i:s'),
                        (int) $event->aggregateId(),
                        $event->bettingGameId(),
                    ]
                );

                continue;
            }

            if ($event instanceof ParticipantApproved) {
                // Approval without a game applies to every pending participation
                $this->db->execute(
                    '
                    UPDATE game_participation
                    SET status = "active"
                    WHERE participant_id = ? AND status = "pending_approval"
                    ',
                    [(int) $event->aggregateId()]
                );
            }
        }
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
