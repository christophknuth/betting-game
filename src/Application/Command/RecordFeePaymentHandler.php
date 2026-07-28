<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\Model\Fee;
use BettingGame\Domain\Repository\FeeRepositoryInterface;
use DateTimeImmutable;

final class RecordFeePaymentHandler
{
    public function __construct(
        private FeeRepositoryInterface $fees
    ) {
    }

    public function handle(RecordFeePaymentCommand $command): CommandResult
    {
        $fee = $this->fees->find($command->feeId);

        if ($fee === null) {
            throw new EntityNotFoundException("Fee {$command->feeId} does not exist");
        }

        match ($command->paymentStatus) {
            Fee::PAID => $fee->markPaid(
                $command->paymentMethod,
                $command->bookedBy,
                $command->paidAt === null ? null : new DateTimeImmutable($command->paidAt),
                $command->note
            ),
            Fee::WAIVED => $fee->waive(
                $command->note ?? '',
                $command->bookedBy
            ),
            Fee::OPEN => $this->assertStillOpen($fee),
            default => throw new InvalidArgumentException(
                "Unknown payment status: {$command->paymentStatus}"
            ),
        };

        $this->fees->save($fee);

        return CommandResult::accepted($fee->id(), "Fee marked {$command->paymentStatus}");
    }

    /**
     * Setting an already open fee to open is a no-op and stays accepted, so a
     * retry does not fail. Re-opening a settled fee is refused: the aggregate
     * treats settlement as final, and reversing a booking is a different
     * operation from correcting a status.
     */
    private function assertStillOpen(Fee $fee): void
    {
        if (!$fee->isOpen()) {
            throw new BusinessRuleViolationException(
                sprintf('This fee is %s and cannot be reopened', $fee->status())
            );
        }
    }
}
