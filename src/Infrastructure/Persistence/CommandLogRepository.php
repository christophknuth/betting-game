<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Repository\CommandLogRepositoryInterface;
use PDOException;

final class CommandLogRepository implements CommandLogRepositoryInterface
{
    public function __construct(private Db $db)
    {
    }

    /**
     * The unique key on idempotency_key is what actually decides the race.
     * Checking first and inserting afterwards would leave a window in which two
     * retries both find nothing and both go on to execute.
     */
    public function claim(string $commandId, string $commandType, ?string $idempotencyKey): bool
    {
        try {
            $this->db->execute(
                "
                INSERT INTO command_log (
                    command_id, idempotency_key, command_type, status, accepted_at
                ) VALUES (?, ?, ?, 'processing', NOW(6))
                ",
                [$commandId, $idempotencyKey, $commandType]
            );

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && $idempotencyKey !== null) {
                return false;
            }

            throw $e;
        }
    }

    public function markCompleted(string $commandId, int $status, string $responseBody, ?int $resourceId): void
    {
        $this->db->execute(
            "
            UPDATE command_log
            SET status = 'completed',
                http_status = ?,
                response_body = ?,
                resource_id = ?,
                completed_at = NOW(6)
            WHERE command_id = ?
            ",
            [$status, $responseBody, $resourceId, $commandId]
        );
    }

    public function markFailed(string $commandId, int $status, string $error): void
    {
        $this->db->execute(
            "
            UPDATE command_log
            SET status = 'failed',
                http_status = ?,
                error_message = ?,
                completed_at = NOW(6)
            WHERE command_id = ?
            ",
            [$status, $error, $commandId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(string $commandId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM command_log WHERE command_id = ?', [$commandId]);
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        return $this->db->fetchOne('SELECT * FROM command_log WHERE idempotency_key = ?', [$idempotencyKey]);
    }
}
