<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\ChangeParticipantStatusCommand;
use BettingGame\Application\Command\ChangeParticipantStatusHandler;
use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Command\RenameParticipantCommand;
use BettingGame\Application\Command\RenameParticipantHandler;
use BettingGame\Application\Query\GetParticipantsHandler;
use BettingGame\Application\Query\GetParticipantsQuery;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * B-21 and B-25: the administrator maintains the roster.
 *
 * Separate from AdminBetRowController, which writes *against* a participant -
 * this one is about the participant existing at all. Both are admin-only; a
 * member neither creates others nor enumerates them (B-16).
 */
final class AdminParticipantController
{
    public function __construct(
        private CreateParticipantHandler $createParticipant,
        private RenameParticipantHandler $renameParticipant,
        private ChangeParticipantStatusHandler $changeStatus,
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

    /**
     * B-25
     *
     * @param array<string, string> $params
     */
    public function rename(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->renameParticipant->handle(new RenameParticipantCommand(
                Input::pathInt($params, 'participantId'),
                Input::string($body, 'displayName')
            ))->toArray()
        );
    }

    /**
     * B-25
     *
     * @param array<string, string> $params
     */
    public function changeStatus(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->changeStatus->handle(new ChangeParticipantStatusCommand(
                Input::pathInt($params, 'participantId'),
                // Required rather than defaulted: "set the status" without
                // saying to what is a mistake, not a request to deactivate
                // somebody.
                Input::requiredBool($body, 'isActive')
            ))->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->participants->handle(new GetParticipantsQuery(
                // ?active=true is what the pickers ask for: the roster shows
                // everybody, a "which participant?" field must not offer
                // someone who has left.
                $request->queryBool('active') ? true : null
            ))->toArray()
        );
    }
}
