<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** B-21: all participants, for the administrator's roster and pickers. */
final class GetParticipantsQuery
{
    /**
     * @param string|null $status null for everybody, or one of ParticipantStatus.
     *     A picker asks for `active`: offering someone who has left the
     *     syndicate - or has not been approved yet - only leads to the `409`
     *     B-11 answers with. The administrator asks for `pending` to see what
     *     is waiting for a decision (E1-01).
     */
    public function __construct(
        public readonly ?string $status = null
    ) {
    }
}
