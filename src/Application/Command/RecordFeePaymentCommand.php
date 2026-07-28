<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-07: book a fee as paid, or waive it. */
final class RecordFeePaymentCommand
{
    public function __construct(
        public readonly int $feeId,
        public readonly string $paymentStatus,
        public readonly ?string $paidAt = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $note = null,
        public readonly ?string $bookedBy = null
    ) {
    }
}
