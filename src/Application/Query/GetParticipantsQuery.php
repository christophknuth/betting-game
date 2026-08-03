<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** B-21: all participants, for the administrator's roster and pickers. */
final class GetParticipantsQuery
{
    /**
     * @param bool|null $isActive null for everybody, true for the ones still
     *     playing. A picker asks for the second: offering someone who has left
     *     the syndicate only leads to the `409` B-11 answers with.
     */
    public function __construct(
        public readonly ?bool $isActive = null
    ) {
    }
}
