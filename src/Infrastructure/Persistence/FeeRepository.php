<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\Fee;
use BettingGame\Domain\Repository\FeeRepositoryInterface;
use DateTimeImmutable;

final class FeeRepository extends EventSourcedRepository implements FeeRepositoryInterface
{
    private const STREAM_PREFIX = 'fee-';

    public function find(int $id): ?Fee
    {
        $row = $this->db->fetchOne('SELECT * FROM fee WHERE fee_id = ?', [$id]);

        return $row === null ? null : $this->toAggregate($row);
    }

    public function save(Fee $fee): void
    {
        $exists = $fee->isPersisted();

        $version = $this->transactionally(function () use ($fee, $exists): int {
            $version = $this->append(
                self::STREAM_PREFIX . $fee->id(),
                $fee->releaseEvents(),
                $fee->originalVersion()
            );

            if ($exists) {
                $this->db->execute(
                    '
                    UPDATE fee
                    SET amount = ?, due_date = ?, payment_status = ?, paid_at = ?,
                        payment_method = ?, booked_by = ?, note = ?, version = ?
                    WHERE fee_id = ?
                    ',
                    [
                        $fee->amount(),
                        $fee->dueDate()->format('Y-m-d'),
                        $fee->status(),
                        $fee->paidAt()?->format('Y-m-d H:i:s'),
                        $fee->paymentMethod(),
                        $fee->bookedBy(),
                        $fee->note(),
                        $version,
                        $fee->id(),
                    ]
                );
            } else {
                // uk_participant_ticket must reject a second fee for the same ticket
                $this->db->execute(
                    '
                    INSERT INTO fee (
                        fee_id, participant_id, ticket_id, amount, due_date,
                        payment_status, paid_at, payment_method, booked_by, note, version
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    [
                        $fee->id(),
                        $fee->participantId(),
                        $fee->ticketId(),
                        $fee->amount(),
                        $fee->dueDate()->format('Y-m-d'),
                        $fee->status(),
                        $fee->paidAt()?->format('Y-m-d H:i:s'),
                        $fee->paymentMethod(),
                        $fee->bookedBy(),
                        $fee->note(),
                        $version,
                    ]
                );
            }

            return $version;
        });

        $fee->markCommitted($version);
    }

    public function nextIdentity(): int
    {
        return $this->nextId('fee', 'fee_id');
    }

    public function findByParticipantAndTicket(int $participantId, int $ticketId): ?Fee
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM fee WHERE participant_id = ? AND ticket_id = ?',
            [$participantId, $ticketId]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    /** @return list<Fee> */
    public function findByTicket(int $ticketId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM fee WHERE ticket_id = ? ORDER BY participant_id',
            [$ticketId]
        );

        return array_map(fn (array $row): Fee => $this->toAggregate($row), $rows);
    }

    /**
     * B-03: the participant's fees together with the period they are owed for -
     * a bare amount and due date says nothing without the ticket behind it.
     *
     * @return list<array<string, mixed>>
     */
    public function findByParticipant(int $participantId): array
    {
        return $this->db->fetchAll(
            '
            SELECT
                f.fee_id, f.ticket_id, f.amount, f.due_date, f.payment_status,
                f.paid_at, f.payment_method, f.note,
                t.period_start, t.period_end, t.tipp_year_id
            FROM fee f
            JOIN ticket t ON t.ticket_id = f.ticket_id
            WHERE f.participant_id = ?
            ORDER BY t.period_start DESC
            ',
            [$participantId]
        );
    }

    public function openTotalOf(int $participantId): float
    {
        $row = $this->db->fetchOne(
            "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM fee
            WHERE participant_id = ? AND payment_status = 'open'
            ",
            [$participantId]
        );

        return $row === null ? 0.0 : Row::float($row, 'total');
    }

    /** @param array<string, mixed> $row */
    private function toAggregate(array $row): Fee
    {
        $paidAt = Row::nullableString($row, 'paid_at');

        return Fee::fromProjection(
            id: Row::int($row, 'fee_id'),
            participantId: Row::int($row, 'participant_id'),
            ticketId: Row::int($row, 'ticket_id'),
            amount: Row::float($row, 'amount'),
            dueDate: new DateTimeImmutable(Row::string($row, 'due_date')),
            status: Row::string($row, 'payment_status'),
            paidAt: $paidAt === null ? null : new DateTimeImmutable($paidAt),
            paymentMethod: Row::nullableString($row, 'payment_method'),
            bookedBy: Row::nullableString($row, 'booked_by'),
            note: Row::nullableString($row, 'note'),
            version: Row::int($row, 'version')
        );
    }
}
