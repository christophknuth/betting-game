<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\RegisterParticipantCommand;
use BettingGame\Application\Command\RegisterParticipantHandler;
use BettingGame\Application\Query\GetMyRegistrationHandler;
use BettingGame\Application\Query\GetMyRegistrationQuery;
use BettingGame\Presentation\Http\Authorization;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * E1-01: the two routes an account without a participant may call.
 *
 * Everything else in this API assumes a participant behind the token. These
 * two are the way to become one - so they are authenticated (the realm decides
 * who gets a login) but need no `participant_id`, and they are the only place
 * where that is true.
 *
 * The account comes from the token's `sub`, never from the body. A registration
 * one caller can send in another's name would be a way to occupy somebody
 * else's account before they get there.
 */
final class RegistrationController
{
    public function __construct(
        private RegisterParticipantHandler $register,
        private GetMyRegistrationHandler $myRegistration
    ) {
    }

    /** @param array<string, string> $params */
    public function register(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->register->handle(new RegisterParticipantCommand(
                Authorization::subject($request),
                Input::string($body, 'displayName')
            ))->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function mine(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->myRegistration->handle(new GetMyRegistrationQuery(
                Authorization::subject($request)
            ))->toArray()
        );
    }
}
