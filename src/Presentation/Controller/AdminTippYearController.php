<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\AddMemberHandler;
use BettingGame\Application\Command\ChangeTippYearStatusCommand;
use BettingGame\Application\Command\ChangeTippYearStatusHandler;
use BettingGame\Application\Command\CreateBetPeriodCommand;
use BettingGame\Application\Command\CreateBetPeriodHandler;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Application\Command\CreateTippYearHandler;
use BettingGame\Application\Command\DistributePayoutCommand;
use BettingGame\Application\Command\DistributePayoutHandler;
use BettingGame\Application\Command\SubmitTicketCommand;
use BettingGame\Application\Command\SubmitTicketHandler;
use BettingGame\Application\Query\GetBetPeriodsHandler;
use BettingGame\Application\Query\GetBetPeriodsQuery;
use BettingGame\Application\Query\GetTippYearsHandler;
use BettingGame\Application\Query\GetTippYearsQuery;
use BettingGame\Presentation\Http\Authorization;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * The tipp year and everything the administrator sets up around it:
 * B-10, B-14, B-11, B-12, B-13 and B-18.
 */
final class AdminTippYearController
{
    public function __construct(
        private CreateTippYearHandler $createTippYear,
        private CreateBetPeriodHandler $createBetPeriod,
        private AddMemberHandler $addMember,
        private SubmitTicketHandler $submitTicket,
        private DistributePayoutHandler $distributePayout,
        private ChangeTippYearStatusHandler $changeTippYearStatus,
        private GetTippYearsHandler $tippYears,
        private GetBetPeriodsHandler $betPeriods
    ) {
    }

    /**
     * B-18
     *
     * @param array<string, string> $params
     */
    public function changeStatus(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->changeTippYearStatus->handle(new ChangeTippYearStatusCommand(
                Input::pathInt($params, 'tippYearId'),
                Input::string($body, 'status')
            ))->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->tippYears->handle(new GetTippYearsQuery($request->queryParam('status')))->toArray()
        );
    }

    /**
     * B-10
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->createTippYear->handle(new CreateTippYearCommand(
                Input::string($body, 'name'),
                Input::string($body, 'startDate'),
                Input::string($body, 'endDate'),
                Input::float($body, 'ticketCostPerRow')
            ))->toArray()
        );
    }

    /** @param array<string, string> $params */
    public function listBetPeriods(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->betPeriods->handle(
                new GetBetPeriodsQuery(Input::pathInt($params, 'tippYearId'))
            )->toArray()
        );
    }

    /**
     * B-14
     *
     * @param array<string, string> $params
     */
    public function createBetPeriod(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->createBetPeriod->handle(new CreateBetPeriodCommand(
                Input::pathInt($params, 'tippYearId'),
                Input::string($body, 'name'),
                Input::string($body, 'startDate'),
                Input::string($body, 'endDate'),
                Input::optionalInt($body, 'sequence')
            ))->toArray()
        );
    }

    /**
     * B-11
     *
     * @param array<string, string> $params
     */
    public function addMember(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->addMember->handle(new AddMemberCommand(
                Input::pathInt($params, 'tippYearId'),
                Input::int($body, 'participantId'),
                Input::optionalString($body, 'joinedAt')
            ))->toArray()
        );
    }

    /**
     * B-12
     *
     * @param array<string, string> $params
     */
    public function submitTicket(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->submitTicket->handle(new SubmitTicketCommand(
                Input::pathInt($params, 'tippYearId'),
                Input::string($body, 'periodStart'),
                Input::string($body, 'periodEnd'),
                Input::int($body, 'drawCount'),
                Input::optionalInt($body, 'superzahl'),
                Input::optionalString($body, 'lotteryReference')
            ))->toArray()
        );
    }

    /**
     * B-13
     *
     * @param array<string, string> $params
     */
    public function distributePayout(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->distributePayout->handle(new DistributePayoutCommand(
                Input::pathInt($params, 'tippYearId'),
                // Defaults to false: an unconfirmed distribution must be
                // refused, never assumed.
                Input::bool($body, 'confirm', false),
                Input::optionalString($body, 'note'),
                $this->bookedBy($request)
            ))->toArray()
        );
    }

    /**
     * Who booked it, taken from the token rather than the body - the client
     * must not get to name someone else as the one who signed off.
     */
    private function bookedBy(Request $request): ?string
    {
        $username = $request->attribute('username');

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return Authorization::isAdmin($request) ? 'admin' : null;
    }
}
