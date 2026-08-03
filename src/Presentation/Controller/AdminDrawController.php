<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\CorrectDrawCommand;
use BettingGame\Application\Command\CorrectDrawHandler;
use BettingGame\Application\Command\RecordDrawCommand;
use BettingGame\Application\Command\RecordDrawHandler;
use BettingGame\Application\Command\RecordDrawWinningsCommand;
use BettingGame\Application\Command\RecordDrawWinningsHandler;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\InvalidInputException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * B-08 and B-09: the draw, and what the ticket won in it.
 */
final class AdminDrawController
{
    public function __construct(
        private RecordDrawHandler $recordDraw,
        private CorrectDrawHandler $correctDraw,
        private RecordDrawWinningsHandler $recordWinnings
    ) {
    }

    /**
     * B-08
     *
     * @param array<string, string> $params
     */
    public function record(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->recordDraw->handle(new RecordDrawCommand(
                Input::int($body, 'tippYearId'),
                Input::string($body, 'drawDate'),
                Input::intList($body, 'numbers'),
                Input::int($body, 'superzahl')
            ))->toArray()
        );
    }

    /**
     * B-28: the draw as it should have been entered.
     *
     * The same three fields as recording it, all of them required - a
     * correction states what is right rather than what changed. Which of them
     * may still be changed at all is the aggregate's business.
     *
     * @param array<string, string> $params
     */
    public function correct(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->correctDraw->handle(new CorrectDrawCommand(
                Input::pathInt($params, 'drawId'),
                Input::string($body, 'drawDate'),
                Input::intList($body, 'numbers'),
                Input::int($body, 'superzahl')
            ))->toArray()
        );
    }

    /**
     * B-09, B-23
     *
     * @param array<string, string> $params
     */
    public function recordWinnings(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        return JsonResponse::accepted(
            $this->recordWinnings->handle(new RecordDrawWinningsCommand(
                Input::pathInt($params, 'drawId'),
                // Optional as of B-23, but only in the sense that the amounts
                // per class replace it. Which of the two belongs there is a
                // domain rule and is answered by WinningStatement, not here.
                Input::optionalFloat($body, 'totalAmount'),
                $this->winningClasses($body)
            ))->toArray()
        );
    }

    /**
     * What one row of each winning class was paid - the second way of stating
     * what the ticket won (B-23).
     *
     * Omitting it is normal, and then the ticket total has to be there instead.
     * What is not acceptable is a half-filled entry, so anything present has to
     * carry both a class and an amount.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array{winningClass: int, amountPerRow: float}>
     */
    private function winningClasses(array $body): array
    {
        $raw = $body['winningClasses'] ?? null;

        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw new InvalidInputException('winningClasses must be an array');
        }

        $classes = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new InvalidInputException('Each winning class must be an object');
            }

            /** @var array<string, mixed> $entry */
            $classes[] = [
                'winningClass' => Input::int($entry, 'winningClass'),
                'amountPerRow' => Input::float($entry, 'amountPerRow'),
            ];
        }

        return $classes;
    }
}
