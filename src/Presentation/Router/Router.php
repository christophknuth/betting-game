<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * Routing table for the base version.
 *
 * `role => admin` marks the routes the front controller gates on the admin
 * role. The participant routes carry no marker because their check is not a
 * role but an identity: the controller compares the path against the token.
 *
 * `{id:\d+}` keeps a non-numeric id from ever reaching a controller, so a
 * mistyped URL is a 404 rather than a 400 from deep inside a handler.
 */
final class Router
{
    private Dispatcher $dispatcher;

    public function __construct()
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $r): void {
            // The only route without authentication - a health check behind a
            // token cannot tell a load balancer whether the service is up.
            $r->addRoute('GET', '/health', [
                'controller' => 'HealthController',
                'method' => 'check',
                'public' => true,
            ]);

            // --- Participant, read only (B-01 to B-04) ---

            $r->addRoute('GET', '/participants/{participantId:\d+}/bet-row', [
                'controller' => 'ParticipantController',
                'method' => 'betRow',
            ]);
            $r->addRoute('GET', '/participants/{participantId:\d+}/memberships', [
                'controller' => 'ParticipantController',
                'method' => 'memberships',
            ]);
            $r->addRoute('GET', '/participants/{participantId:\d+}/fees', [
                'controller' => 'ParticipantController',
                'method' => 'fees',
            ]);
            $r->addRoute('GET', '/participants/{participantId:\d+}/payout-share', [
                'controller' => 'ParticipantController',
                'method' => 'payoutShare',
            ]);

            // --- Tipp year, shared result (B-05) ---

            $r->addRoute('GET', '/tipp-years/{tippYearId:\d+}/draws', [
                'controller' => 'TippYearController',
                'method' => 'draws',
            ]);

            // --- Admin: participants (B-21, B-25) ---

            $r->addRoute('GET', '/admin/participants', [
                'controller' => 'AdminParticipantController',
                'method' => 'list',
                'role' => 'admin',
            ]);
            $r->addRoute('POST', '/admin/participants', [
                'controller' => 'AdminParticipantController',
                'method' => 'create',
                'role' => 'admin',
                'command' => true,
            ]);
            // The name is the only thing about a participant worth correcting;
            // the status below decides whether they still play. Deleting is
            // not offered - see ChangeParticipantStatusHandler.
            $r->addRoute('PUT', '/admin/participants/{participantId:\d+}', [
                'controller' => 'AdminParticipantController',
                'method' => 'rename',
                'role' => 'admin',
                'command' => true,
            ]);
            $r->addRoute('PUT', '/admin/participants/{participantId:\d+}/status', [
                'controller' => 'AdminParticipantController',
                'method' => 'changeStatus',
                'role' => 'admin',
                'command' => true,
            ]);

            // --- Admin: bet rows (B-06) ---

            $r->addRoute('PUT', '/admin/participants/{participantId:\d+}/bet-row', [
                'controller' => 'AdminBetRowController',
                'method' => 'assign',
                'role' => 'admin',
                'command' => true,
            ]);

            // --- Admin: fees (B-07) ---

            $r->addRoute('GET', '/admin/fees', [
                'controller' => 'AdminFeeController',
                'method' => 'list',
                'role' => 'admin',
            ]);
            $r->addRoute('PUT', '/admin/fees/{feeId:\d+}/payment', [
                'controller' => 'AdminFeeController',
                'method' => 'recordPayment',
                'role' => 'admin',
                'command' => true,
            ]);

            // --- Admin: draws (B-08, B-09) ---

            $r->addRoute('POST', '/admin/draws', [
                'controller' => 'AdminDrawController',
                'method' => 'record',
                'role' => 'admin',
                'command' => true,
            ]);
            $r->addRoute('PUT', '/admin/draws/{drawId:\d+}/winnings', [
                'controller' => 'AdminDrawController',
                'method' => 'recordWinnings',
                'role' => 'admin',
                'command' => true,
            ]);

            // --- Admin: tipp year (B-10 to B-14) ---

            $r->addRoute('GET', '/admin/tipp-years', [
                'controller' => 'AdminTippYearController',
                'method' => 'list',
                'role' => 'admin',
            ]);
            $r->addRoute('POST', '/admin/tipp-years', [
                'controller' => 'AdminTippYearController',
                'method' => 'create',
                'role' => 'admin',
                'command' => true,
            ]);
            // B-18. Every transition is allowed; that at most one year runs is
            // enforced by the handler and, under concurrency, by a unique key.
            $r->addRoute('PUT', '/admin/tipp-years/{tippYearId:\d+}/status', [
                'controller' => 'AdminTippYearController',
                'method' => 'changeStatus',
                'role' => 'admin',
                'command' => true,
            ]);
            $r->addRoute('GET', '/admin/tipp-years/{tippYearId:\d+}/bet-periods', [
                'controller' => 'AdminTippYearController',
                'method' => 'listBetPeriods',
                'role' => 'admin',
            ]);
            $r->addRoute('POST', '/admin/tipp-years/{tippYearId:\d+}/bet-periods', [
                'controller' => 'AdminTippYearController',
                'method' => 'createBetPeriod',
                'role' => 'admin',
                'command' => true,
            ]);
            $r->addRoute('POST', '/admin/tipp-years/{tippYearId:\d+}/members', [
                'controller' => 'AdminTippYearController',
                'method' => 'addMember',
                'role' => 'admin',
                'command' => true,
            ]);
            $r->addRoute('POST', '/admin/tipp-years/{tippYearId:\d+}/tickets', [
                'controller' => 'AdminTippYearController',
                'method' => 'submitTicket',
                'role' => 'admin',
                'command' => true,
            ]);
            $r->addRoute('POST', '/admin/tipp-years/{tippYearId:\d+}/payout', [
                'controller' => 'AdminTippYearController',
                'method' => 'distributePayout',
                'role' => 'admin',
                'command' => true,
            ]);

            // --- Operations (OPS-01, OPS-03, OPS-04) ---

            // Not admin-only: whoever issued a command may ask what became of
            // it, and the id is a UUID nobody else can guess.
            $r->addRoute('GET', '/commands/{commandId}', [
                'controller' => 'CommandStatusController',
                'method' => 'show',
            ]);

            $r->addRoute('GET', '/admin/audit/{aggregateType:[a-z_]+}/{aggregateId:\d+}', [
                'controller' => 'AdminOperationsController',
                'method' => 'audit',
                'role' => 'admin',
            ]);
            $r->addRoute('GET', '/admin/projections', [
                'controller' => 'AdminOperationsController',
                'method' => 'projections',
                'role' => 'admin',
            ]);
            // Not marked as a command: a rebuild changes no domain state, and
            // logging it as one would put it in the command history.
            $r->addRoute('POST', '/admin/projections/{name:[a-z_]+}/rebuild', [
                'controller' => 'AdminOperationsController',
                'method' => 'rebuild',
                'role' => 'admin',
            ]);
        });
    }

    /**
     * FastRoute result: [NOT_FOUND] | [METHOD_NOT_ALLOWED, list<string>]
     * | [FOUND, array{controller: string, method: string, role?: string}, array<string, string>]
     *
     * @return array<int, mixed>
     */
    public function dispatch(string $httpMethod, string $uri): array
    {
        /** @var array<int, mixed> $routeInfo */
        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);

        return $routeInfo;
    }
}
