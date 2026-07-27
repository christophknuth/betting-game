<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use DateTimeImmutable;

final class GameEventRepository implements GameEventRepositoryInterface
{
    public function __construct(private Db $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM event WHERE event_id = ?', [$id]);
    }

    public function getDeadline(int $eventId): ?DateTimeImmutable
    {
        $row = $this->db->fetchOne('SELECT deadline FROM event WHERE event_id = ?', [$eventId]);

        if ($row === null) {
            return null;
        }

        $deadline = Row::nullableString($row, 'deadline');

        return $deadline !== null ? new DateTimeImmutable($deadline) : null;
    }
}
