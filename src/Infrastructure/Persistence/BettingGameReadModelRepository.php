<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Application\Query\BettingGameReadModel;
use BettingGame\Application\Query\BettingGameReadModelRepositoryInterface;

final class BettingGameReadModelRepository implements BettingGameReadModelRepositoryInterface
{
    /**
     * Counts come from correlated subqueries rather than joins, so a game
     * without participants or events still yields exactly one row.
     */
    private const SELECT = '
        SELECT
            bg.betting_game_id,
            bg.name,
            bg.description,
            bg.status,
            bg.start_date,
            bg.end_date,
            bg.base_fee,
            bg.fee_period_days,
            bg.created_at,
            gt.game_type_id,
            gt.type_name,
            gt.category,
            (
                SELECT COUNT(*)
                FROM game_participation gp
                WHERE gp.betting_game_id = bg.betting_game_id
                  AND gp.status <> "ended"
            ) AS participant_count,
            (
                SELECT COUNT(*)
                FROM event e
                WHERE e.betting_game_id = bg.betting_game_id
            ) AS event_count
        FROM betting_game bg
        INNER JOIN game_type gt ON bg.game_type_id = gt.game_type_id
    ';

    public function __construct(private Db $db)
    {
    }

    /**
     * @return list<BettingGameReadModel>
     */
    public function findAll(?string $status = null, ?int $gameTypeId = null): array
    {
        $sql = self::SELECT . ' WHERE 1 = 1';
        $params = [];

        if ($status !== null) {
            $sql .= ' AND bg.status = ?';
            $params[] = $status;
        }

        if ($gameTypeId !== null) {
            $sql .= ' AND bg.game_type_id = ?';
            $params[] = $gameTypeId;
        }

        $sql .= ' ORDER BY bg.start_date DESC';

        $readModels = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $readModels[] = $this->hydrate($row);
        }

        return $readModels;
    }

    public function findById(int $bettingGameId): ?BettingGameReadModel
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE bg.betting_game_id = ?', [$bettingGameId]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BettingGameReadModel
    {
        $bettingGameId = Row::int($row, 'betting_game_id');

        return new BettingGameReadModel(
            bettingGameId: $bettingGameId,
            name: Row::string($row, 'name'),
            description: Row::nullableString($row, 'description') ?? '',
            gameType: [
                'gameTypeId' => Row::int($row, 'game_type_id'),
                'typeName' => Row::string($row, 'type_name'),
                'category' => Row::string($row, 'category'),
            ],
            status: Row::string($row, 'status'),
            startDate: Row::string($row, 'start_date'),
            endDate: Row::string($row, 'end_date'),
            baseFee: Row::nullableFloat($row, 'base_fee'),
            feePeriodDays: Row::nullableInt($row, 'fee_period_days'),
            participantCount: Row::int($row, 'participant_count'),
            eventCount: Row::int($row, 'event_count'),
            configuration: $this->findConfiguration($bettingGameId, Row::string($row, 'category')),
            createdAt: Row::string($row, 'created_at')
        );
    }

    /**
     * Which configuration applies follows from the game type's category -
     * sports games score points, lottery games distribute a prize pool.
     *
     * @return array<string, mixed>|null
     */
    private function findConfiguration(int $bettingGameId, string $category): ?array
    {
        if ($category === 'lottery') {
            $row = $this->db->fetchOne(
                'SELECT * FROM prize_distribution WHERE betting_game_id = ?',
                [$bettingGameId]
            );

            if ($row === null) {
                return null;
            }

            return [
                'totalPrizePool' => Row::float($row, 'total_prize_pool'),
                'distributionSchema' => Row::string($row, 'distribution_schema'),
                'rankPercentages' => Row::json($row, 'rank_percentages'),
                'minWinners' => Row::nullableInt($row, 'min_winners') ?? 1,
                'maxWinners' => Row::nullableInt($row, 'max_winners'),
            ];
        }

        $row = $this->db->fetchOne(
            'SELECT * FROM point_configuration WHERE betting_game_id = ?',
            [$bettingGameId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'scoringRuleName' => Row::string($row, 'scoring_rule_name'),
            'pointsExactMatch' => Row::int($row, 'points_exact_match'),
            'pointsCloseMatch' => Row::nullableInt($row, 'points_close_match') ?? 0,
            'pointsPartialMatch' => Row::nullableInt($row, 'points_partial_match') ?? 0,
            'pointsWrong' => Row::nullableInt($row, 'points_wrong') ?? 0,
        ];
    }
}
