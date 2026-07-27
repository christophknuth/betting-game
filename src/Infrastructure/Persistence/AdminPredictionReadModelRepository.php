<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\AdminPredictionReadModelRepositoryInterface;
use BettingGame\Application\Query\PredictionReadModel;

final class AdminPredictionReadModelRepository implements AdminPredictionReadModelRepositoryInterface
{
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
    public function findAll(
        ?int $bettingGameId = null,
        ?int $eventId = null,
        ?int $participantId = null,
        int $page = 1,
        int $pageSize = 50
    ): array {
        [$where, $params] = $this->filter($bettingGameId, $eventId, $participantId);

        // Both values are ints by signature, so interpolating them cannot inject.
        $limit = max(1, $pageSize);
        $offset = (max(1, $page) - 1) * $limit;

        $sql = self::SELECT . $where . ' ORDER BY p.submitted_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $readModels = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $readModels[] = $this->hydrate($row);
        }

        return $readModels;
    }

    public function countAll(
        ?int $bettingGameId = null,
        ?int $eventId = null,
        ?int $participantId = null
    ): int {
        [$where, $params] = $this->filter($bettingGameId, $eventId, $participantId);

        $row = $this->db->fetchOne(
            '
            SELECT COUNT(*) AS cnt
            FROM prediction p
            INNER JOIN event e ON p.event_id = e.event_id
            ' . $where,
            $params
        );

        return $row !== null ? Row::int($row, 'cnt') : 0;
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function filter(?int $bettingGameId, ?int $eventId, ?int $participantId): array
    {
        $where = ' WHERE 1 = 1';
        $params = [];

        if ($bettingGameId !== null) {
            $where .= ' AND e.betting_game_id = ?';
            $params[] = $bettingGameId;
        }

        if ($eventId !== null) {
            $where .= ' AND p.event_id = ?';
            $params[] = $eventId;
        }

        if ($participantId !== null) {
            $where .= ' AND p.participant_id = ?';
            $params[] = $participantId;
        }

        return [$where, $params];
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
