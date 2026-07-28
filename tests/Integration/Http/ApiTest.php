<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Http;

use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Infrastructure\Auth\KeycloakService;
use BettingGame\Presentation\Controller\AdminBetRowController;
use BettingGame\Presentation\Controller\AdminDrawController;
use BettingGame\Presentation\Controller\AdminFeeController;
use BettingGame\Presentation\Controller\AdminTippYearController;
use BettingGame\Presentation\Controller\HealthController;
use BettingGame\Presentation\Controller\ParticipantController;
use BettingGame\Presentation\Controller\TippYearController;
use BettingGame\Presentation\Http\ErrorMapper;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Kernel;
use BettingGame\Presentation\Http\Request;
use BettingGame\Presentation\Router\Router;
use BettingGame\Tests\Integration\Application\ApplicationTestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The whole chain through the front controller: routing, authentication, the
 * role gate, the controller and the exception mapping.
 *
 * Everything below this has its own tests; what only shows up here is whether
 * a domain exception really becomes the status code the API documents, and
 * whether a route is reachable by someone who should not reach it.
 */
final class ApiTest extends ApplicationTestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();

        $controllers = [
            'BettingGame\Presentation\Controller\HealthController' => new HealthController(),
            'BettingGame\Presentation\Controller\ParticipantController' => new ParticipantController(
                $this->getBetRow(),
                $this->getMemberships(),
                $this->getParticipantFees(),
                $this->getPayoutShare()
            ),
            'BettingGame\Presentation\Controller\TippYearController' => new TippYearController(
                $this->getDraws()
            ),
            'BettingGame\Presentation\Controller\AdminBetRowController' => new AdminBetRowController(
                $this->assignBetRow()
            ),
            'BettingGame\Presentation\Controller\AdminDrawController' => new AdminDrawController(
                $this->recordDraw(),
                $this->recordDrawWinnings()
            ),
            'BettingGame\Presentation\Controller\AdminFeeController' => new AdminFeeController(
                $this->recordFeePayment(),
                $this->getFees()
            ),
            'BettingGame\Presentation\Controller\AdminTippYearController' => new AdminTippYearController(
                $this->createTippYear(),
                $this->createBetPeriod(),
                $this->addMember(),
                $this->submitTicket(),
                $this->distributePayout(),
                $this->getTippYears(),
                $this->getBetPeriods()
            ),
        ];

        $container = new class ($controllers) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id] ?? throw new \RuntimeException("Unknown service $id");
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };

        $this->kernel = new Kernel(
            $container,
            new Router(),
            new AuthMiddleware(new KeycloakService(), new NullLogger()),
            new ErrorMapper(true)
        );
    }

    /**
     * A token the way KeycloakService currently reads it.
     *
     * Note it carries no valid signature - validateToken does not verify one
     * yet (see its own comment). That is exactly why this test can forge a
     * token, and why the same is true for anyone else.
     *
     * @param list<string> $roles
     */
    private function token(?int $participantId, array $roles = []): string
    {
        $encode = static fn (array $data): string => rtrim(
            strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'),
            '='
        );

        return $encode(['alg' => 'RS256', 'typ' => 'JWT'])
            . '.'
            . $encode([
                'iss' => 'http://keycloak:8080/realms/betting-game',
                'exp' => time() + 3600,
                'participant_id' => $participantId,
                'preferred_username' => 'tester',
                'realm_access' => ['roles' => $roles],
            ])
            . '.unverified';
    }

    /** @param array<string, mixed> $body */
    private function send(
        string $method,
        string $uri,
        ?string $token = null,
        array $body = []
    ): JsonResponse {
        $path = parse_url($uri, PHP_URL_PATH);
        $queryString = parse_url($uri, PHP_URL_QUERY);

        $query = [];
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        return $this->kernel->handle(new Request(
            method: $method,
            uri: is_string($path) ? $path : '/',
            headers: $token === null ? [] : ['AUTHORIZATION' => 'Bearer ' . $token],
            query: $query,
            body: $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        ));
    }

    // --- Routing ---

    public function testHealthNeedsNoToken(): void
    {
        $response = $this->send('GET', '/health');

        self::assertSame(200, $response->statusCode());
        self::assertSame('lotto-syndicate', $response->data()['domain']);
    }

    public function testAnUnknownRouteIs404(): void
    {
        self::assertSame(404, $this->send('GET', '/nope')->statusCode());
    }

    public function testAWrongMethodIs405(): void
    {
        $response = $this->send('DELETE', '/health');

        self::assertSame(405, $response->statusCode());
        self::assertStringContainsString('GET', (string) $response->data()['message']);
    }

    public function testANonNumericIdDoesNotReachAController(): void
    {
        self::assertSame(404, $this->send('GET', '/participants/abc/bet-row')->statusCode());
    }

    // --- Authentication and authorisation ---

    public function testWithoutATokenAParticipantRouteIs401(): void
    {
        self::assertSame(401, $this->send('GET', '/participants/7/bet-row')->statusCode());
    }

    public function testAnotherParticipantsDataIs403(): void
    {
        $response = $this->send('GET', '/participants/7/bet-row', $this->token(8));

        self::assertSame(403, $response->statusCode(), 'B-16: only your own data');
    }

    public function testTheOwnershipCheckRunsBeforeTheQuery(): void
    {
        // Participant 7 does not exist at all. Answering 404 here would confirm
        // to participant 8 that there is nothing to find, which is already more
        // than they may know.
        self::assertSame(403, $this->send('GET', '/participants/7/bet-row', $this->token(8))->statusCode());
    }

    public function testAdminRoutesRejectANonAdmin(): void
    {
        $response = $this->send('GET', '/admin/tipp-years', $this->token(7));

        self::assertSame(403, $response->statusCode(), 'B-17: the admin area is role protected');
    }

    public function testAdminRoutesAcceptAnAdmin(): void
    {
        $response = $this->send('GET', '/admin/tipp-years', $this->token(1, ['admin']));

        self::assertSame(200, $response->statusCode());
        self::assertSame([], $response->data()['tippYears']);
    }

    public function testATokenWithoutAParticipantClaimCannotReachOwnData(): void
    {
        self::assertSame(403, $this->send('GET', '/participants/7/bet-row', $this->token(null))->statusCode());
    }

    // --- A full administrative flow over HTTP ---

    public function testTheAdminCanRunAWholeYearOverHttp(): void
    {
        $admin = $this->token(1, ['admin']);
        $this->givenParticipant(7, 'Anna');

        $created = $this->send('POST', '/admin/tipp-years', $admin, [
            'name' => 'Tippjahr 2026',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
            'ticketCostPerRow' => 1.20,
        ]);

        self::assertSame(202, $created->statusCode(), 'commands answer 202, not 201');
        self::assertSame('accepted', $created->data()['status']);
        $tippYearId = $created->data()['resourceId'];
        self::assertIsInt($tippYearId);

        $period = $this->send('POST', "/admin/tipp-years/$tippYearId/bet-periods", $admin, [
            'name' => '2026 gesamt',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
        ]);
        self::assertSame(202, $period->statusCode());
        $betPeriodId = $period->data()['resourceId'];
        self::assertIsInt($betPeriodId);

        self::assertSame(202, $this->send('POST', "/admin/tipp-years/$tippYearId/members", $admin, [
            'participantId' => 7,
        ])->statusCode());

        self::assertSame(202, $this->send('PUT', '/admin/participants/7/bet-row', $admin, [
            'betPeriodId' => $betPeriodId,
            'numbers' => [3, 12, 19, 27, 33, 45],
        ])->statusCode());

        $this->startTippYear($tippYearId);

        self::assertSame(202, $this->send('POST', "/admin/tipp-years/$tippYearId/tickets", $admin, [
            'periodStart' => '2026-01-01',
            'periodEnd' => '2026-01-31',
            'drawCount' => 9,
            'superzahl' => 7,
        ])->statusCode());

        $draw = $this->send('POST', '/admin/draws', $admin, [
            'tippYearId' => $tippYearId,
            'drawDate' => '2026-01-07',
            'numbers' => [3, 12, 19, 27, 40, 41],
            'superzahl' => 7,
        ]);
        self::assertSame(202, $draw->statusCode());
        $drawId = $draw->data()['resourceId'];
        self::assertIsInt($drawId);

        self::assertSame(202, $this->send('PUT', "/admin/draws/$drawId/winnings", $admin, [
            'totalAmount' => 123.45,
        ])->statusCode());

        // The participant can now see it all
        $participant = $this->token(7);

        $betRow = $this->send('GET', '/participants/7/bet-row?betPeriodId=' . $betPeriodId, $participant);
        self::assertSame(200, $betRow->statusCode());
        self::assertSame([3, 12, 19, 27, 33, 45], $betRow->data()['numbers']);

        $fees = $this->send('GET', '/participants/7/fees', $participant);
        self::assertSame(200, $fees->statusCode());
        self::assertSame(10.80, $fees->data()['summary']['totalOpen']);

        $draws = $this->send('GET', "/tipp-years/$tippYearId/draws", $participant);
        self::assertSame(200, $draws->statusCode());
        self::assertSame(123.45, $draws->data()['totalWinnings']);
    }

    // --- Exceptions become the documented status codes ---

    public function testARejectedBusinessRuleIs409(): void
    {
        $admin = $this->token(1, ['admin']);
        $this->givenParticipant(7, 'Anna');

        $year = $this->send('POST', '/admin/tipp-years', $admin, [
            'name' => 'Tippjahr 2026',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
            'ticketCostPerRow' => 1.20,
        ]);
        $tippYearId = $year->data()['resourceId'];

        $period = $this->send('POST', "/admin/tipp-years/$tippYearId/bet-periods", $admin, [
            'name' => '2026 gesamt',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
        ]);
        $betPeriodId = $period->data()['resourceId'];

        $this->send('PUT', '/admin/participants/7/bet-row', $admin, [
            'betPeriodId' => $betPeriodId,
            'numbers' => [3, 12, 19, 27, 33, 45],
        ]);

        // B-06: a second row for the period without a reason
        $conflict = $this->send('PUT', '/admin/participants/7/bet-row', $admin, [
            'betPeriodId' => $betPeriodId,
            'numbers' => [1, 2, 3, 4, 5, 6],
        ]);

        self::assertSame(409, $conflict->statusCode());
        self::assertSame('Conflict', $conflict->data()['error']);

        // With a reason it goes through
        self::assertSame(202, $this->send('PUT', '/admin/participants/7/bet-row', $admin, [
            'betPeriodId' => $betPeriodId,
            'numbers' => [1, 2, 3, 4, 5, 6],
            'replaceReason' => 'wrong slip transcribed',
        ])->statusCode());
    }

    public function testAMissingEntityIs404(): void
    {
        $response = $this->send('GET', '/tipp-years/999/draws', $this->token(7));

        self::assertSame(404, $response->statusCode());
    }

    public function testAMalformedBodyIs400(): void
    {
        $response = $this->send('POST', '/admin/draws', $this->token(1, ['admin']), [
            'tippYearId' => 1,
            'drawDate' => '2026-01-07',
            'numbers' => 'not an array',
            'superzahl' => 7,
        ]);

        self::assertSame(400, $response->statusCode());
        self::assertSame('Bad Request', $response->data()['error']);
    }

    public function testAnInvalidValueObjectIs400(): void
    {
        $admin = $this->token(1, ['admin']);
        $this->givenParticipant(7, 'Anna');

        $year = $this->send('POST', '/admin/tipp-years', $admin, [
            'name' => 'Tippjahr 2026',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
            'ticketCostPerRow' => 1.20,
        ]);
        $tippYearId = $year->data()['resourceId'];

        $period = $this->send('POST', "/admin/tipp-years/$tippYearId/bet-periods", $admin, [
            'name' => '2026 gesamt',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
        ]);

        // Only five numbers - LottoNumbers refuses, and that is a 400
        $response = $this->send('PUT', '/admin/participants/7/bet-row', $admin, [
            'betPeriodId' => $period->data()['resourceId'],
            'numbers' => [3, 12, 19, 27, 33],
        ]);

        self::assertSame(400, $response->statusCode());
    }

    public function testAMistypedQueryFilterIs400(): void
    {
        $response = $this->send('GET', '/participants/7/fees?tippYearId=abc', $this->token(7));

        self::assertSame(400, $response->statusCode(), 'a filter must not silently become 0');
    }
}
