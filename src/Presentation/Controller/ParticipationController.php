<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\JoinGameCommand;
use BettingGame\Application\Command\JoinGameHandler;
use BettingGame\Application\Command\LeaveGameCommand;
use BettingGame\Application\Command\LeaveGameHandler;
use BettingGame\Application\Query\GetParticipationsQuery;
use BettingGame\Application\Query\GetParticipationsHandler;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\InvalidInputException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class ParticipationController
{
    public function __construct(
        private JoinGameHandler $joinGameHandler,
        private LeaveGameHandler $leaveGameHandler,
        private GetParticipationsHandler $getParticipationsHandler
    ) {
    }

    /** @param array<string, string> $params */
    public function getParticipations(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $query = new GetParticipationsQuery(
            participantId: $participantId,
            status: $request->queryParam('status')
        );

        try {
            $result = $this->getParticipationsHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function joinGame(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        $bettingGameId = (int) $params['bettingGameId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $body = $request->jsonBody();

        if (!array_key_exists('acceptTerms', $body)) {
            return JsonResponse::badRequest('acceptTerms is required');
        }

        try {
            $command = new JoinGameCommand(
                participantId: $participantId,
                bettingGameId: $bettingGameId,
                acceptTerms: Input::bool($body, 'acceptTerms', false),
                paymentReference: Input::optionalString($body, 'paymentReference'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

        try {
            $result = $this->joinGameHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function leaveGame(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        $bettingGameId = (int) $params['bettingGameId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $command = new LeaveGameCommand(
            participantId: $participantId,
            bettingGameId: $bettingGameId,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->leaveGameHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    private function isAuthorized(Request $request, int $participantId): bool
    {
        $authParticipantId = $request->attribute('participant_id');
        return $authParticipantId === $participantId;
    }
}
