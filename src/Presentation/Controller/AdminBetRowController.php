<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\AssignBetRowCommand;
use BettingGame\Application\Command\AssignBetRowHandler;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * B-06: assigning a participant's bet row for a period.
 *
 * The only write against a participant in the base version, and it is the
 * administrator's - self service is E1.
 */
final class AdminBetRowController
{
    public function __construct(
        private AssignBetRowHandler $assignBetRow
    ) {
    }

    /** @param array<string, string> $params */
    public function assign(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->assignBetRow->handle(new AssignBetRowCommand(
                Input::pathInt($params, 'participantId'),
                Input::int($body, 'betPeriodId'),
                Input::intList($body, 'numbers'),
                Input::optionalString($body, 'replaceReason')
            ))->toArray()
        );
    }
}
