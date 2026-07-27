<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\BettingGame;
use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\ValueObject\GameStatus;
use DateTimeImmutable;

final class BettingGameRepository implements BettingGameRepositoryInterface
{
    private const STREAM_PREFIX = 'betting_game-';

    public function __construct(
        private Db $db,
        private EventStoreInterface $eventStore
    ) {
    }

    public function save(BettingGame $game): void
    {
        $events = $game->releaseEvents();
        $expectedVersion = $game->originalVersion();

        if ($events !== []) {
            $this->eventStore->append(
                self::STREAM_PREFIX . $game->id(),
                $events,
                $expectedVersion
            );
        }

        // The projection version mirrors the stream version, so the next load
        // knows which version to expect when appending.
        $this->updateProjection($game, $expectedVersion + count($events));
        $this->updateConfiguration($game);
    }

    public function findById(int $id): ?BettingGame
    {
        $row = $this->db->fetchOne('SELECT * FROM betting_game WHERE betting_game_id = ?', [$id]);

        if ($row === null) {
            return null;
        }

        return BettingGame::fromProjection(
            id: Row::int($row, 'betting_game_id'),
            name: Row::string($row, 'name'),
            description: Row::nullableString($row, 'description') ?? '',
            gameTypeId: Row::int($row, 'game_type_id'),
            startDate: new DateTimeImmutable(Row::string($row, 'start_date')),
            endDate: new DateTimeImmutable(Row::string($row, 'end_date')),
            status: new GameStatus(Row::string($row, 'status')),
            baseFee: Row::nullableFloat($row, 'base_fee'),
            feePeriodDays: Row::nullableInt($row, 'fee_period_days'),
            pointConfiguration: $this->findPointConfiguration($id),
            prizeDistribution: $this->findPrizeDistribution($id),
            createdAt: new DateTimeImmutable(Row::string($row, 'created_at')),
            version: Row::int($row, 'version')
        );
    }

    public function nextIdentity(): int
    {
        $row = $this->db->fetchOne('SELECT COALESCE(MAX(betting_game_id), 0) + 1 AS next_id FROM betting_game');

        return $row !== null ? Row::int($row, 'next_id') : 1;
    }

    private function updateProjection(BettingGame $game, int $version): void
    {
        $this->db->execute(
            '
            INSERT INTO betting_game (
                betting_game_id, name, description, game_type_id,
                start_date, end_date, status, base_fee, fee_period_days, created_at, version
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                start_date = VALUES(start_date),
                end_date = VALUES(end_date),
                status = VALUES(status),
                base_fee = VALUES(base_fee),
                fee_period_days = VALUES(fee_period_days),
                version = VALUES(version)
            ',
            [
                $game->id(),
                $game->name(),
                $game->description(),
                $game->gameTypeId(),
                $game->startDate()->format('Y-m-d H:i:s'),
                $game->endDate()->format('Y-m-d H:i:s'),
                $game->status()->value(),
                $game->baseFee(),
                $game->feePeriodDays(),
                $game->createdAt()->format('Y-m-d H:i:s'),
                $version,
            ]
        );
    }

    /**
     * Point configuration and prize distribution are mutually exclusive side
     * tables - which one applies follows from the game type's category.
     */
    private function updateConfiguration(BettingGame $game): void
    {
        $points = $game->pointConfiguration();

        if ($points !== null) {
            $this->db->execute(
                '
                INSERT INTO point_configuration (
                    betting_game_id, scoring_rule_name, points_exact_match,
                    points_close_match, points_partial_match, points_wrong
                )
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    scoring_rule_name = VALUES(scoring_rule_name),
                    points_exact_match = VALUES(points_exact_match),
                    points_close_match = VALUES(points_close_match),
                    points_partial_match = VALUES(points_partial_match),
                    points_wrong = VALUES(points_wrong)
                ',
                [
                    $game->id(),
                    $this->configString($points, 'scoringRuleName', 'default'),
                    $this->configInt($points, 'pointsExactMatch'),
                    $this->configInt($points, 'pointsCloseMatch'),
                    $this->configInt($points, 'pointsPartialMatch'),
                    $this->configInt($points, 'pointsWrong'),
                ]
            );
        }

        $prize = $game->prizeDistribution();

        if ($prize !== null) {
            $this->db->execute(
                '
                INSERT INTO prize_distribution (
                    betting_game_id, total_prize_pool, distribution_schema,
                    rank_percentages, min_winners, max_winners
                )
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_prize_pool = VALUES(total_prize_pool),
                    distribution_schema = VALUES(distribution_schema),
                    rank_percentages = VALUES(rank_percentages),
                    min_winners = VALUES(min_winners),
                    max_winners = VALUES(max_winners)
                ',
                [
                    $game->id(),
                    $this->configFloat($prize, 'totalPrizePool'),
                    $this->configString($prize, 'distributionSchema', 'percentage'),
                    json_encode($prize['rankPercentages'] ?? [], JSON_THROW_ON_ERROR),
                    $this->configInt($prize, 'minWinners', 1),
                    $this->configInt($prize, 'maxWinners'),
                ]
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function findPointConfiguration(int $bettingGameId): ?array
    {
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

    /** @return array<string, mixed>|null */
    private function findPrizeDistribution(int $bettingGameId): ?array
    {
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

    /** @param array<string, mixed> $config */
    private function configInt(array $config, string $key, int $default = 0): int
    {
        $value = $config[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /** @param array<string, mixed> $config */
    private function configFloat(array $config, string $key, float $default = 0.0): float
    {
        $value = $config[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : $default;
    }

    /** @param array<string, mixed> $config */
    private function configString(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
