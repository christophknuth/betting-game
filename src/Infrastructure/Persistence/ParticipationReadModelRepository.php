<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\ParticipationReadModel;
use BettingGame\Application\Query\ParticipationReadModelRepositoryInterface;

final class ParticipationReadModelRepository implements ParticipationReadModelRepositoryInterface
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @return list<ParticipationReadModel>
     */
    public function findByParticipant(int $participantId, ?string $status = null): array
    {
        $sql = '
            SELECT
                gp.participant_id,
                gp.betting_game_id,
                gp.status,
                gp.joined_at,
                bg.name AS betting_game_name,
                bg.start_date,
                bg.end_date,
                bg.base_fee,
                gt.type_name,
                (
                    SELECT COALESCE(SUM(ps.points_earned), 0)
                    FROM participant_score ps
                    WHERE ps.participant_id = gp.participant_id
                      AND ps.betting_game_id = gp.betting_game_id
                ) AS current_points,
                (
                    SELECT COALESCE(SUM(ps.prize_amount), 0)
                    FROM participant_score ps
                    WHERE ps.participant_id = gp.participant_id
                      AND ps.betting_game_id = gp.betting_game_id
                ) AS current_prize_amount,
                (
                    SELECT COUNT(*)
                    FROM fee f
                    WHERE f.participant_id = gp.participant_id
                      AND f.betting_game_id = gp.betting_game_id
                      AND f.payment_status <> "paid"
                ) AS open_fee_count
            FROM game_participation gp
            INNER JOIN betting_game bg ON gp.betting_game_id = bg.betting_game_id
            INNER JOIN game_type gt ON bg.game_type_id = gt.game_type_id
            WHERE gp.participant_id = ?
        ';

        $params = [$participantId];

        if ($status !== null) {
            $sql .= ' AND gp.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY gp.joined_at DESC';

        $readModels = [];

        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $baseFee = Row::nullableFloat($row, 'base_fee');
            $feesRequired = $baseFee !== null && $baseFee > 0.0;

            $readModels[] = new ParticipationReadModel(
                participantId: Row::int($row, 'participant_id'),
                bettingGameId: Row::int($row, 'betting_game_id'),
                bettingGameName: Row::string($row, 'betting_game_name'),
                gameType: Row::string($row, 'type_name'),
                status: Row::string($row, 'status'),
                joinedAt: Row::string($row, 'joined_at'),
                startDate: Row::string($row, 'start_date'),
                endDate: Row::string($row, 'end_date'),
                currentPoints: Row::nullableInt($row, 'current_points'),
                currentPrizeAmount: Row::nullableFloat($row, 'current_prize_amount'),
                feesRequired: $feesRequired,
                // Without a fee obligation there is nothing outstanding to report
                feesPaid: !$feesRequired || Row::int($row, 'open_fee_count') === 0
            );
        }

        return $readModels;
    }
}
