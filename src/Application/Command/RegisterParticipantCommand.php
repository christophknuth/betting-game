<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** E1-01: somebody registers themselves as a participant. */
final class RegisterParticipantCommand
{
    /**
     * @param string $keycloakSubject the account that asked, from the token's `sub`.
     *     Never from the body: a caller must not be able to register on somebody
     *     else's behalf, and the token is the only thing here that was signed
     */
    public function __construct(
        public readonly string $keycloakSubject,
        public readonly string $displayName
    ) {
    }
}
