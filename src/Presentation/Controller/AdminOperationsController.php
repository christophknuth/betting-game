<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Projection\ProjectionManager;
use BettingGame\Application\Projection\ProjectionStatus;
use BettingGame\Application\Query\GetAuditTrailHandler;
use BettingGame\Application\Query\GetAuditTrailQuery;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * OPS-03 and OPS-04: the operator's view of the event store and the read models.
 */
final class AdminOperationsController
{
    public function __construct(
        private GetAuditTrailHandler $auditTrail,
        private ProjectionManager $projections
    ) {
    }

    /**
     * OPS-03
     *
     * @param array<string, string> $params
     */
    public function audit(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok(
            $this->auditTrail->handle(new GetAuditTrailQuery(
                $params['aggregateType'] ?? '',
                $params['aggregateId'] ?? ''
            ))->toArray()
        );
    }

    /**
     * OPS-04
     *
     * @param array<string, string> $params
     */
    public function projections(Request $request, array $params): JsonResponse
    {
        return JsonResponse::ok([
            'projections' => array_map(
                static fn (ProjectionStatus $status): array => $status->toArray(),
                $this->projections->statuses()
            ),
        ]);
    }

    /**
     * OPS-04. A rebuild is synchronous and can take a while on a large store;
     * the response is the state afterwards, not an acknowledgement.
     *
     * The list that comes back is usually longer than the one projection asked
     * for - resetting a read model cascades into the ones below it, so those
     * are rebuilt too.
     *
     * @param array<string, string> $params
     */
    public function rebuild(Request $request, array $params): JsonResponse
    {
        $name = $params['name'] ?? '';

        $rebuilt = $name === 'all'
            ? $this->projections->rebuildAll()
            : $this->projections->rebuild($name);

        return JsonResponse::ok([
            'rebuilt' => array_map(
                static fn (ProjectionStatus $status): array => $status->toArray(),
                $rebuilt
            ),
        ]);
    }
}
