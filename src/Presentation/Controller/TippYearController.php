<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Query\GetDrawsHandler;
use BettingGame\Application\Query\GetDrawsQuery;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * B-05: the draws of a tipp year and what the ticket won.
 *
 * No ownership check here, and that is deliberate: this shows the syndicate's
 * shared result, the same figures for every member. Nothing personal is in it -
 * a participant's share only exists after the annual distribution.
 */
final class TippYearController
{
    public function __construct(
        private GetDrawsHandler $draws
    ) {
    }

    /** @param array<string, string> $params */
    public function draws(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->draws->handle(
                new GetDrawsQuery(
                    Input::pathInt($params, 'tippYearId'),
                    $request->queryParam('status'),
                    $request->queryBool('withWinningsOnly')
                )
            )->toArray()
        );
    }
}
