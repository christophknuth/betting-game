<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Query\GetParticipantsHandler;
use BettingGame\Application\Query\GetParticipantsQuery;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * B-21: the administrator maintains the roster.
 *
 * Separate from AdminBetRowController, which writes *against* a participant -
 * this one is about the participant existing at all. Both are admin-only; a
 * member neither creates others nor enumerates them (B-16).
 */
final class AdminParticipantController
{
    public function __construct(
        private CreateParticipantHandler $createParticipant,
        private GetParticipantsHandler $participants
    ) {
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->createParticipant->handle(new CreateParticipantCommand(
                Input::string($body, 'displayName')
            ))->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->participants->handle(new GetParticipantsQuery())->toArray()
        );
    }
}
