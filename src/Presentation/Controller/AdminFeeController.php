<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\RecordFeePaymentCommand;
use BettingGame\Application\Command\RecordFeePaymentHandler;
use BettingGame\Application\Query\GetFeesHandler;
use BettingGame\Application\Query\GetFeesQuery;
use BettingGame\Presentation\Http\Authorization;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * B-07: booking the fees, and the ledger to find the open ones.
 */
final class AdminFeeController
{
    public function __construct(
        private RecordFeePaymentHandler $recordPayment,
        private GetFeesHandler $fees
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->fees->handle(new GetFeesQuery(
                $request->queryInt('tippYearId'),
                $request->queryInt('participantId'),
                $request->queryParam('paymentStatus')
            ))->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function recordPayment(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->recordPayment->handle(new RecordFeePaymentCommand(
                Input::pathInt($params, 'feeId'),
                Input::string($body, 'paymentStatus'),
                Input::optionalString($body, 'paidAt'),
                Input::optionalString($body, 'paymentMethod'),
                Input::optionalString($body, 'note'),
                // From the token, so the audit trail names the actual admin
                $this->bookedBy($request)
            ))->toArray()
        );
    }

    private function bookedBy(Request $request): ?string
    {
        $username = $request->attribute('username');

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return Authorization::isAdmin($request) ? 'admin' : null;
    }
}
