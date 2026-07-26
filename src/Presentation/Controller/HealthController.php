<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class HealthController
{
    public function check(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok([
            'status' => 'healthy',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
