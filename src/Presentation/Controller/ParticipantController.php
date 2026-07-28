<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Query\GetBetRowHandler;
use BettingGame\Application\Query\GetBetRowQuery;
use BettingGame\Application\Query\GetMembershipsHandler;
use BettingGame\Application\Query\GetMembershipsQuery;
use BettingGame\Application\Query\GetParticipantFeesHandler;
use BettingGame\Application\Query\GetParticipantFeesQuery;
use BettingGame\Application\Query\GetPayoutShareHandler;
use BettingGame\Application\Query\GetPayoutShareQuery;
use BettingGame\Presentation\Http\Authorization;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * The participant's own data - B-01 to B-04.
 *
 * Read only, on purpose: in the base version every booking is the
 * administrator's, so there is nothing here but GET.
 *
 * Each action starts with the ownership check. It has to come before the query
 * runs, or a 404 for someone else's missing data would already confirm that
 * they have none.
 */
final class ParticipantController
{
    public function __construct(
        private GetBetRowHandler $betRows,
        private GetMembershipsHandler $memberships,
        private GetParticipantFeesHandler $fees,
        private GetPayoutShareHandler $payoutShare
    ) {
    }

    /** @param array<string, string> $params */
    public function betRow(Request $request, array $params): JsonResponse
    {
        $participantId = Input::pathInt($params, 'participantId');
        Authorization::requireSelf($request, $participantId);

        return JsonResponse::ok(
            $this->betRows->handle(
                new GetBetRowQuery($participantId, $request->queryInt('betPeriodId'))
            )->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function memberships(Request $request, array $params): JsonResponse
    {
        $participantId = Input::pathInt($params, 'participantId');
        Authorization::requireSelf($request, $participantId);

        return JsonResponse::ok(
            $this->memberships->handle(
                new GetMembershipsQuery($participantId, $request->queryInt('tippYearId'))
            )->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function fees(Request $request, array $params): JsonResponse
    {
        $participantId = Input::pathInt($params, 'participantId');
        Authorization::requireSelf($request, $participantId);

        return JsonResponse::ok(
            $this->fees->handle(
                new GetParticipantFeesQuery(
                    $participantId,
                    $request->queryInt('tippYearId'),
                    $request->queryParam('paymentStatus')
                )
            )->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function payoutShare(Request $request, array $params): JsonResponse
    {
        $participantId = Input::pathInt($params, 'participantId');
        Authorization::requireSelf($request, $participantId);

        return JsonResponse::ok(
            $this->payoutShare->handle(
                new GetPayoutShareQuery($participantId, $request->queryInt('tippYearId'))
            )->toArray()
        );
    }
}
