<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class JoinGameCommand
{
    public function __construct(
        public readonly int $participantId,
        public readonly int $bettingGameId,
        public readonly bool $acceptTerms,
        public readonly ?string $paymentReference = null,
        public readonly ?string $correlationId = null
    ) {
    }
}
