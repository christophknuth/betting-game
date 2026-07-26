<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\ScoreReadModelRepositoryInterface;
use BettingGame\Application\Query\ParticipantScoreReadModel;
use PDO;

final class ScoreReadModelRepository implements ScoreReadModelRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByParticipant(int $participantId, ?int $bettingGameId = null): array
    {
        $sql = '
            SELECT 
                ps.score_id,
                ps.participant_id,
                ps.betting_game_id,
                bg.name as betting_game_name,
                ps.event_id,
                e.event_name,
                ps.points_earned,
                ps.prize_amount,
                ps.calculated_at
            FROM participant_score ps
            INNER JOIN betting_game bg ON ps.betting_game_id = bg.betting_game_id
            INNER JOIN event e ON ps.event_id = e.event_id
            WHERE ps.participant_id = ?
        ';

        $params = [$participantId];

        if ($bettingGameId !== null) {
            $sql .= ' AND ps.betting_game_id = ?';
            $params[] = $bettingGameId;
        }

        $sql .= ' ORDER BY ps.calculated_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $readModels = [];
        foreach ($rows as $row) {
            $readModels[] = new ParticipantScoreReadModel(
                scoreId: (int) $row['score_id'],
                participantId: (int) $row['participant_id'],
                bettingGameId: (int) $row['betting_game_id'],
                bettingGameName: $row['betting_game_name'],
                eventId: (int) $row['event_id'],
                eventName: $row['event_name'],
                pointsEarned: $row['points_earned'] !== null ? (int) $row['points_earned'] : null,
                prizeAmount: $row['prize_amount'] !== null ? (float) $row['prize_amount'] : null,
                calculatedAt: $row['calculated_at']
            );
        }

        return $readModels;
    }

    public function getSummary(int $participantId): array
    {
        $sql = '
            SELECT 
                COALESCE(SUM(points_earned), 0) as total_points,
                COALESCE(SUM(prize_amount), 0) as total_prize_amount,
                COUNT(DISTINCT betting_game_id) as games_participated
            FROM participant_score
            WHERE participant_id = ?
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$participantId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'totalPoints' => (int) $result['total_points'],
            'totalPrizeAmount' => (float) $result['total_prize_amount'],
            'gamesParticipated' => (int) $result['games_participated'],
        ];
    }
}
