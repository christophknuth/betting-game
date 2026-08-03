<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** E1-01: what became of the registration of the account that is asking. */
final class GetMyRegistrationQuery
{
    public function __construct(
        public readonly string $keycloakSubject
    ) {
    }
}
