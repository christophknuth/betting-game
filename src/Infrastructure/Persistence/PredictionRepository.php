<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\Prediction;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\PredictionData;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

final class PredictionRepository implements PredictionRepositoryInterface
{
    private const STREAM_PREFIX = 'prediction-';

    private const SELECT = '
        SELECT
            p.prediction_id,
            p.participant_id,
            p.event_id,
            p.prediction_data,
            p.submitted_at,
            p.updated_at,
            p.version,
            ps.points_earned,
            ps.prize_amount
        FROM prediction p
        LEFT JOIN participant_score ps ON p.prediction_id = ps.prediction_id
    ';

    public function __construct(
        private Db $db,
        private EventStoreInterface $eventStore
    ) {
    }

    public function save(Prediction $prediction): void
    {
        $events = $prediction->releaseEvents();
        $expectedVersion = $prediction->originalVersion();

        if ($events !== []) {
            $this->eventStore->append(
                self::STREAM_PREFIX . $prediction->id(),
                $events,
                $expectedVersion
            );
        }

        // The projection version mirrors the stream version, so the next load
        // knows which version to expect when appending.
        $this->updateProjection($prediction, $expectedVersion + count($events));
    }

    public function findById(string $id): ?Prediction
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE p.prediction_id = ?', [$id]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @return list<Prediction>
     */
    public function findByParticipant(ParticipantId $participantId): array
    {
        $rows = $this->db->fetchAll(
            self::SELECT . ' WHERE p.participant_id = ? ORDER BY p.submitted_at DESC',
            [$participantId->value()]
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @return list<Prediction>
     */
    public function findByEvent(EventId $eventId): array
    {
        $rows = $this->db->fetchAll(
            self::SELECT . ' WHERE p.event_id = ? ORDER BY p.submitted_at ASC',
            [$eventId->value()]
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function exists(ParticipantId $participantId, EventId $eventId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM prediction WHERE participant_id = ? AND event_id = ?',
            [$participantId->value(), $eventId->value()]
        );

        return $row !== null && Row::int($row, 'cnt') > 0;
    }

    public function nextIdentity(): string
    {
        return Uuid::uuid4()->toString();
    }

    private function updateProjection(Prediction $prediction, int $version): void
    {
        $this->db->execute(
            '
            INSERT INTO prediction (
                prediction_id, participant_id, event_id, prediction_data,
                submitted_at, updated_at, version
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                prediction_data = VALUES(prediction_data),
                updated_at = VALUES(updated_at),
                version = VALUES(version)
            ',
            [
                $prediction->id(),
                $prediction->participantId()->value(),
                $prediction->eventId()->value(),
                $prediction->predictionData()->toJson(),
                $prediction->submittedAt()->format('Y-m-d H:i:s'),
                $prediction->updatedAt()?->format('Y-m-d H:i:s'),
                $version,
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Prediction
    {
        $updatedAt = Row::nullableString($row, 'updated_at');
        $pointsEarned = Row::nullableInt($row, 'points_earned');
        $prizeAmount = Row::nullableFloat($row, 'prize_amount');

        return Prediction::fromProjection(
            id: Row::string($row, 'prediction_id'),
            participantId: new ParticipantId(Row::int($row, 'participant_id')),
            eventId: new EventId(Row::int($row, 'event_id')),
            predictionData: new PredictionData(Row::json($row, 'prediction_data')),
            submittedAt: new DateTimeImmutable(Row::string($row, 'submitted_at')),
            updatedAt: $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
            pointsEarned: $pointsEarned,
            prizeAmount: $prizeAmount,
            evaluated: $pointsEarned !== null || $prizeAmount !== null,
            version: Row::int($row, 'version')
        );
    }
}
