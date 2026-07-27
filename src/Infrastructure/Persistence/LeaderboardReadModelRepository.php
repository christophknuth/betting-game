<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\LeaderboardReadModel;
use BettingGame\Application\Query\LeaderboardReadModelRepositoryInterface;
use PDO;

final class LeaderboardReadModelRepository implements LeaderboardReadModelRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getLeaderboard(int $bettingGameId, int $limit = 50): ?LeaderboardReadModel
    {
        $game = $this->findGame($bettingGameId);

        if ($game === null) {
            return null;
        }

        return new LeaderboardReadModel(
            bettingGameId: $bettingGameId,
            bettingGameName: $game['name'],
            rankings: $this->rankings($bettingGameId, $limit),
            updatedAt: $this->lastCalculatedAt($bettingGameId)
        );
    }

    private function findGame(int $bettingGameId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT name FROM betting_game WHERE betting_game_id = ?');
        $stmt->execute([$bettingGameId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Rank is not stored anywhere - it is derived from the ordering of the aggregate.
     */
    private function rankings(int $bettingGameId, int $limit): array
    {
        // $limit is an int by signature, so interpolating it cannot inject.
        $sql = '
            SELECT
                p.participant_id,
                p.display_name,
                COALESCE(SUM(ps.points_earned), 0) AS total_points,
                COALESCE(SUM(ps.prize_amount), 0) AS total_prize_amount,
                (
                    SELECT COUNT(*)
                    FROM prediction pred
                    INNER JOIN event ev ON pred.event_id = ev.event_id
                    WHERE pred.participant_id = p.participant_id
                      AND ev.betting_game_id = :game_id_predictions
                ) AS predictions_count
            FROM participant_score ps
            INNER JOIN participant p ON p.participant_id = ps.participant_id
            WHERE ps.betting_game_id = :game_id
            GROUP BY p.participant_id, p.display_name
            ORDER BY total_points DESC, total_prize_amount DESC, p.display_name ASC
            LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'game_id_predictions' => $bettingGameId,
            'game_id' => $bettingGameId,
        ]);

        $rankings = [];
        $rank = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rank++;

            $rankings[] = [
                'rank' => $rank,
                'participantId' => (int) $row['participant_id'],
                'displayName' => $row['display_name'],
                'totalPoints' => (int) $row['total_points'],
                'totalPrizeAmount' => (float) $row['total_prize_amount'],
                'predictionsCount' => (int) $row['predictions_count'],
            ];
        }

        return $rankings;
    }

    private function lastCalculatedAt(int $bettingGameId): string
    {
        $stmt = $this->pdo->prepare('
            SELECT MAX(calculated_at) AS last_calculated_at
            FROM participant_score
            WHERE betting_game_id = ?
        ');
        $stmt->execute([$bettingGameId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastCalculatedAt = $row['last_calculated_at'] ?? null;

        return $lastCalculatedAt !== null
            ? (new \DateTimeImmutable($lastCalculatedAt))->format('c')
            : (new \DateTimeImmutable())->format('c');
    }
}
