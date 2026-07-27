<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\ParticipantReadModel;
use BettingGame\Application\Query\ParticipantReadModelRepositoryInterface;

final class ParticipantReadModelRepository implements ParticipantReadModelRepositoryInterface
{
    /**
     * `status` is not a column - it is derived from is_active plus whether the
     * participant is still waiting for approval in any game.
     */
    private const SELECT = '
        SELECT
            p.participant_id,
            p.user_id,
            p.display_name,
            p.registered_at,
            p.is_active,
            (
                SELECT COUNT(*)
                FROM game_participation gp
                WHERE gp.participant_id = p.participant_id
                  AND gp.status = "pending_approval"
            ) AS pending_count,
            (
                SELECT COUNT(DISTINCT gp.betting_game_id)
                FROM game_participation gp
                WHERE gp.participant_id = p.participant_id
            ) AS games_participated,
            (
                SELECT COALESCE(SUM(ps.points_earned), 0)
                FROM participant_score ps
                WHERE ps.participant_id = p.participant_id
            ) AS total_points,
            (
                SELECT COALESCE(SUM(ps.prize_amount), 0)
                FROM participant_score ps
                WHERE ps.participant_id = p.participant_id
            ) AS total_prizes
        FROM participant p
    ';

    public function __construct(private Db $db)
    {
    }

    /**
     * @return list<ParticipantReadModel>
     */
    public function findAll(?string $status = null, ?int $bettingGameId = null): array
    {
        $sql = self::SELECT . ' WHERE 1 = 1';
        $params = [];

        if ($bettingGameId !== null) {
            $sql .= '
                AND EXISTS (
                    SELECT 1 FROM game_participation gp2
                    WHERE gp2.participant_id = p.participant_id
                      AND gp2.betting_game_id = ?
                )';
            $params[] = $bettingGameId;
        }

        $sql .= match ($status) {
            'active' => ' AND p.is_active = 1',
            'inactive' => ' AND p.is_active = 0',
            'pending_approval' => '
                AND EXISTS (
                    SELECT 1 FROM game_participation gp3
                    WHERE gp3.participant_id = p.participant_id
                      AND gp3.status = "pending_approval"
                )',
            default => '',
        };

        $sql .= ' ORDER BY p.display_name ASC';

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, $params));
    }

    /**
     * @return list<ParticipantReadModel>
     */
    public function findPendingByGame(int $bettingGameId): array
    {
        $sql = self::SELECT . '
            INNER JOIN game_participation gp ON gp.participant_id = p.participant_id
            WHERE gp.betting_game_id = ?
              AND gp.status = "pending_approval"
            ORDER BY gp.joined_at ASC
        ';

        return array_map($this->hydrate(...), $this->db->fetchAll($sql, [$bettingGameId]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ParticipantReadModel
    {
        $isActive = Row::bool($row, 'is_active');
        $pendingCount = Row::int($row, 'pending_count');

        return new ParticipantReadModel(
            participantId: Row::int($row, 'participant_id'),
            userId: Row::int($row, 'user_id'),
            displayName: Row::string($row, 'display_name'),
            status: $this->deriveStatus($isActive, $pendingCount),
            registeredAt: Row::string($row, 'registered_at'),
            gamesParticipated: Row::int($row, 'games_participated'),
            totalPoints: Row::int($row, 'total_points'),
            totalPrizes: Row::float($row, 'total_prizes')
        );
    }

    private function deriveStatus(bool $isActive, int $pendingCount): string
    {
        if (!$isActive) {
            return 'inactive';
        }

        return $pendingCount > 0 ? 'pending_approval' : 'active';
    }
}
