<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use PDO;
use DateTimeImmutable;

final class GameEventRepository implements GameEventRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event WHERE event_id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getDeadline(int $eventId): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT deadline FROM event WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || !$result['deadline']) {
            return null;
        }

        return new DateTimeImmutable($result['deadline']);
    }
}
