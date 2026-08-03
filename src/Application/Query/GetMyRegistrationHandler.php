<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Repository\ParticipantRepositoryInterface;

/**
 * E1-01: the state of one's own registration.
 *
 * The one endpoint an account without a participant may call and get an answer
 * to. It exists so the interface can say which of three situations somebody is
 * in - nothing sent yet, waiting, or refused - instead of showing an empty page
 * and leaving them to guess. An approved registration needs no answer here: by
 * then the participant views work.
 *
 * `registered: false` is a fact, not a `404`. Asking the question is legitimate
 * for anyone who is logged in, and "you have not registered" is the answer.
 */
final class GetMyRegistrationHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(GetMyRegistrationQuery $query): QueryResult
    {
        $participant = $this->participants->findByKeycloakSubject($query->keycloakSubject);

        if ($participant === null) {
            return new QueryResult(['registered' => false]);
        }

        return new QueryResult([
            'registered' => true,
            'participantId' => $participant->id(),
            'displayName' => $participant->displayName()->value(),
            'status' => $participant->status()->value(),
        ]);
    }
}
