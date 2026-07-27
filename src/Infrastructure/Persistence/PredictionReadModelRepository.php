<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\PredictionReadModelRepositoryInterface;
use BettingGame\Application\Query\PredictionReadModel;

final class PredictionReadModelRepository implements PredictionReadModelRepositoryInterface
{
    /**
     * Shared projection of a prediction together with its event, result and score.
     */
    private const SELECT = '
        SELECT
            p.prediction_id,
            p.participant_id,
            p.event_id,
            e.event_name,
            p.prediction_data,
            p.submitted_at,
            p.updated_at,
            e.deadline,
            CASE
                WHEN r.result_id IS NOT NULL THEN "evaluated"
                WHEN NOW() > e.deadline THEN "pending"
                ELSE "submitted"
            END as status,
            CASE
                WHEN r.result_id IS NULL AND NOW() <= e.deadline THEN 1
                ELSE 0
            END as is_editable,
            ps.points_earned,
            ps.prize_amount
        FROM prediction p
        INNER JOIN event e ON p.event_id = e.event_id
        LEFT JOIN result r ON e.event_id = r.event_id
        LEFT JOIN participant_score ps ON p.prediction_id = ps.prediction_id
    ';

    public function __construct(private Db $db)
    {
    }

    /**
     * @return list<PredictionReadModel>
     */
    public function findByParticipant(
        int $participantId,
        ?int $bettingGameId = null,
        ?int $eventId = null,
        ?string $status = null
    ): array {
        $sql = self::SELECT . ' WHERE p.participant_id = ?';

        $params = [$participantId];

        if ($bettingGameId !== null) {
            $sql .= ' AND e.betting_game_id = ?';
            $params[] = $bettingGameId;
        }

        if ($eventId !== null) {
            $sql .= ' AND p.event_id = ?';
            $params[] = $eventId;
        }

        if ($status !== null) {
            $sql .= ' HAVING status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY e.event_date DESC';

        $readModels = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $readModels[] = $this->hydrate($row);
        }

        return $readModels;
    }

    public function findById(string $predictionId): ?PredictionReadModel
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE p.prediction_id = ?', [$predictionId]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PredictionReadModel
    {
        $pointsEarned = Row::nullableInt($row, 'points_earned');
        $prizeAmount = Row::nullableFloat($row, 'prize_amount');

        $result = null;
        if ($pointsEarned !== null || $prizeAmount !== null) {
            $result = [
                'pointsEarned' => $pointsEarned,
                'prizeAmount' => $prizeAmount,
            ];
        }

        return new PredictionReadModel(
            predictionId: Row::string($row, 'prediction_id'),
            participantId: Row::int($row, 'participant_id'),
            eventId: Row::int($row, 'event_id'),
            eventName: Row::string($row, 'event_name'),
            predictionData: Row::json($row, 'prediction_data'),
            submittedAt: Row::string($row, 'submitted_at'),
            updatedAt: Row::nullableString($row, 'updated_at'),
            deadline: Row::string($row, 'deadline'),
            status: Row::string($row, 'status'),
            isEditable: Row::bool($row, 'is_editable'),
            result: $result
        );
    }
}
