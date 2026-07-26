<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\PredictionReadModelRepositoryInterface;
use BettingGame\Application\Query\PredictionReadModel;
use PDO;

final class PredictionReadModelRepository implements PredictionReadModelRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByParticipant(
        int $participantId,
        ?int $bettingGameId = null,
        ?int $eventId = null,
        ?string $status = null
    ): array {
        $sql = '
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
            WHERE p.participant_id = ?
        ';

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

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $readModels = [];
        foreach ($rows as $row) {
            $result = null;
            if ($row['points_earned'] !== null || $row['prize_amount'] !== null) {
                $result = [
                    'pointsEarned' => $row['points_earned'],
                    'prizeAmount' => $row['prize_amount'] ? (float) $row['prize_amount'] : null,
                ];
            }

            $readModels[] = new PredictionReadModel(
                predictionId: $row['prediction_id'],
                participantId: (int) $row['participant_id'],
                eventId: (int) $row['event_id'],
                eventName: $row['event_name'],
                predictionData: json_decode($row['prediction_data'], true),
                submittedAt: $row['submitted_at'],
                updatedAt: $row['updated_at'],
                deadline: $row['deadline'],
                status: $row['status'],
                isEditable: (bool) $row['is_editable'],
                result: $result
            );
        }

        return $readModels;
    }

    public function findById(string $predictionId): ?PredictionReadModel
    {
        $sql = '
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
            WHERE p.prediction_id = ?
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$predictionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $result = null;
        if ($row['points_earned'] !== null || $row['prize_amount'] !== null) {
            $result = [
                'pointsEarned' => $row['points_earned'],
                'prizeAmount' => $row['prize_amount'] ? (float) $row['prize_amount'] : null,
            ];
        }

        return new PredictionReadModel(
            predictionId: $row['prediction_id'],
            participantId: (int) $row['participant_id'],
            eventId: (int) $row['event_id'],
            eventName: $row['event_name'],
            predictionData: json_decode($row['prediction_data'], true),
            submittedAt: $row['submitted_at'],
            updatedAt: $row['updated_at'],
            deadline: $row['deadline'],
            status: $row['status'],
            isEditable: (bool) $row['is_editable'],
            result: $result
        );
    }
}
