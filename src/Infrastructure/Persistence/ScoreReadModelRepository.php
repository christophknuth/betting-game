<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\ScoreReadModelRepositoryInterface;
use BettingGame\Application\Query\ParticipantScoreReadModel;

final class ScoreReadModelRepository implements ScoreReadModelRepositoryInterface
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @return list<ParticipantScoreReadModel>
     */
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

        $readModels = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $readModels[] = new ParticipantScoreReadModel(
                scoreId: Row::int($row, 'score_id'),
                participantId: Row::int($row, 'participant_id'),
                bettingGameId: Row::int($row, 'betting_game_id'),
                bettingGameName: Row::string($row, 'betting_game_name'),
                eventId: Row::int($row, 'event_id'),
                eventName: Row::string($row, 'event_name'),
                pointsEarned: Row::nullableInt($row, 'points_earned'),
                prizeAmount: Row::nullableFloat($row, 'prize_amount'),
                calculatedAt: Row::string($row, 'calculated_at')
            );
        }

        return $readModels;
    }

    /** @return array<string, mixed> */
    public function getSummary(int $participantId): array
    {
        $row = $this->db->fetchOne(
            '
            SELECT
                COALESCE(SUM(points_earned), 0) as total_points,
                COALESCE(SUM(prize_amount), 0) as total_prize_amount,
                COUNT(DISTINCT betting_game_id) as games_participated
            FROM participant_score
            WHERE participant_id = ?
            ',
            [$participantId]
        );

        if ($row === null) {
            return [
                'totalPoints' => 0,
                'totalPrizeAmount' => 0.0,
                'gamesParticipated' => 0,
            ];
        }

        return [
            'totalPoints' => Row::int($row, 'total_points'),
            'totalPrizeAmount' => Row::float($row, 'total_prize_amount'),
            'gamesParticipated' => Row::int($row, 'games_participated'),
        ];
    }
}
