<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Repository\ProjectionStateRepositoryInterface;
use BettingGame\Support\Row;

final class ProjectionStateRepository implements ProjectionStateRepositoryInterface
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @return array{status: string, lastProcessedPosition: int, updatedAt: string|null, error: string|null}|null
     */
    public function find(string $name): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT status, last_processed_position, updated_at, error_message
             FROM projection_state WHERE projection_name = ?',
            [$name]
        );

        if ($row === null) {
            return null;
        }

        return [
            'status' => Row::string($row, 'status'),
            'lastProcessedPosition' => Row::int($row, 'last_processed_position'),
            'updatedAt' => Row::nullableString($row, 'updated_at'),
            'error' => Row::nullableString($row, 'error_message'),
        ];
    }

    public function markRebuilding(string $name): void
    {
        $this->upsert($name, 'rebuilding', 0, null);
    }

    public function markRunning(string $name, int $position): void
    {
        $this->upsert($name, 'running', $position, null);
    }

    public function markFailed(string $name, string $error): void
    {
        $this->db->execute(
            "
            INSERT INTO projection_state (projection_name, last_processed_position, status, error_message)
            VALUES (?, 0, 'failed', ?)
            ON DUPLICATE KEY UPDATE status = 'failed', error_message = VALUES(error_message)
            ",
            [$name, $error]
        );
    }

    public function advance(string $name, int $position): void
    {
        // Only the position moves. `status` and `error_message` keep whatever a
        // rebuild left there - see the interface for why.
        $this->db->execute(
            "
            INSERT INTO projection_state (projection_name, last_processed_position, status, error_message)
            VALUES (?, ?, 'running', NULL)
            ON DUPLICATE KEY UPDATE last_processed_position = VALUES(last_processed_position)
            ",
            [$name, $position]
        );
    }

    private function upsert(string $name, string $status, int $position, ?string $error): void
    {
        $this->db->execute(
            '
            INSERT INTO projection_state (projection_name, last_processed_position, status, error_message)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_processed_position = VALUES(last_processed_position),
                status = VALUES(status),
                error_message = VALUES(error_message)
            ',
            [$name, $position, $status, $error]
        );
    }
}
