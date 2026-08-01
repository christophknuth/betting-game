<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Http;

use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Support\Row;

/**
 * OPS-01 to OPS-04 over HTTP.
 */
final class OperationsApiTest extends HttpTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->token(1, ['admin']);
        $this->givenParticipant(7, 'Anna');
    }

    /** @param array<string, mixed> $body */
    private function createTippYearOverHttp(array $body = [], ?string $key = null): JsonResponse
    {
        return $this->send(
            'POST',
            '/admin/tipp-years',
            $this->admin,
            $body === [] ? [
                'name' => 'Tippjahr 2026',
                'startDate' => '2026-01-01',
                'endDate' => '2026-12-31',
                'ticketCostPerRow' => 1.20,
            ] : $body,
            $key === null ? [] : ['Idempotency-Key' => $key]
        );
    }

    // --- OPS-01: command status ---

    public function testACommandCanBeLookedUpAfterwards(): void
    {
        $created = $this->createTippYearOverHttp();

        self::assertSame(202, $created->statusCode());
        $commandId = $created->data()['commandId'];
        self::assertIsString($commandId);

        $status = $this->send('GET', "/commands/$commandId", $this->token(7));

        self::assertSame(200, $status->statusCode());
        self::assertSame($commandId, $status->data()['commandId']);
        self::assertSame('completed', $status->data()['status']);
        self::assertSame(202, $status->data()['httpStatus']);
        self::assertSame($created->data()['resourceId'], $status->data()['resourceId']);
        self::assertTrue($status->data()['projectionsUpToDate']);
        self::assertNull($status->data()['error']);
        self::assertStringContainsString('AdminTippYearController', (string) $status->data()['commandType']);
    }

    public function testAFailedCommandIsRecordedAsFailed(): void
    {
        $this->createTippYearOverHttp();

        // Overlaps the first year, so it is rejected with a 409
        $rejected = $this->createTippYearOverHttp([
            'name' => 'Overlapping',
            'startDate' => '2026-06-01',
            'endDate' => '2027-05-31',
            'ticketCostPerRow' => 1.20,
        ]);

        self::assertSame(409, $rejected->statusCode());

        $failed = $this->db->fetchAll("SELECT * FROM command_log WHERE status = 'failed'");
        self::assertCount(1, $failed);
        self::assertSame(409, Row::int($failed[0], 'http_status'));
        self::assertStringContainsString('overlaps', Row::string($failed[0], 'error_message'));
    }

    public function testAnUnknownCommandIs404(): void
    {
        self::assertSame(
            404,
            $this->send('GET', '/commands/6ba7b810-9dad-11d1-80b4-00c04fd430c8', $this->token(7))->statusCode()
        );
    }

    public function testAMalformedCommandIdIs400(): void
    {
        self::assertSame(400, $this->send('GET', '/commands/not-a-uuid', $this->token(7))->statusCode());
    }

    public function testCommandStatusNeedsAToken(): void
    {
        self::assertSame(401, $this->send('GET', '/commands/6ba7b810-9dad-11d1-80b4-00c04fd430c8')->statusCode());
    }

    // --- OPS-02: idempotency ---

    public function testARetryWithTheSameKeyDoesNotWriteTwice(): void
    {
        $first = $this->createTippYearOverHttp([], 'key-1');
        $second = $this->createTippYearOverHttp([], 'key-1');

        self::assertSame(202, $first->statusCode());
        self::assertSame(202, $second->statusCode(), 'the retry replays the original response');
        self::assertSame($first->data(), $second->data());
        self::assertSame('true', $second->headers()['Idempotent-Replay'] ?? null);

        // And only one tipp year exists - the retry did not execute
        $years = $this->send('GET', '/admin/tipp-years', $this->admin);
        self::assertCount(1, $years->data()['tippYears']);
    }

    public function testWithoutAKeyTheSameCommandRunsAgain(): void
    {
        $this->createTippYearOverHttp();

        // No key, so nothing dedupes it - and the second one legitimately
        // conflicts with the first because the years overlap.
        $second = $this->createTippYearOverHttp();

        self::assertSame(409, $second->statusCode());
    }

    public function testDifferentKeysAreDifferentCommands(): void
    {
        $this->createTippYearOverHttp([
            'name' => 'Tippjahr 2026',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
            'ticketCostPerRow' => 1.20,
        ], 'key-1');

        $second = $this->createTippYearOverHttp([
            'name' => 'Tippjahr 2027',
            'startDate' => '2027-01-01',
            'endDate' => '2027-12-31',
            'ticketCostPerRow' => 1.30,
        ], 'key-2');

        self::assertSame(202, $second->statusCode());
        self::assertCount(2, $this->send('GET', '/admin/tipp-years', $this->admin)->data()['tippYears']);
    }

    public function testARetryOfAFailedCommandIsReplayedAsTheSameFailure(): void
    {
        $this->createTippYearOverHttp();

        $rejected = $this->createTippYearOverHttp([
            'name' => 'Overlapping',
            'startDate' => '2026-06-01',
            'endDate' => '2027-05-31',
            'ticketCostPerRow' => 1.20,
        ], 'key-fail');

        self::assertSame(409, $rejected->statusCode());

        // The key stays claimed, so the retry does not run the command again.
        // A failed attempt stored no response body, so the caller is told the
        // key is spent rather than being handed a fabricated result.
        $retry = $this->createTippYearOverHttp([
            'name' => 'Overlapping',
            'startDate' => '2026-06-01',
            'endDate' => '2027-05-31',
            'ticketCostPerRow' => 1.20,
        ], 'key-fail');

        self::assertSame(409, $retry->statusCode());
    }

    public function testEveryCommandIsLogged(): void
    {
        $this->createTippYearOverHttp();

        $logged = $this->db->fetchAll('SELECT * FROM command_log');

        self::assertCount(1, $logged);
        self::assertSame('completed', Row::string($logged[0], 'status'));
        self::assertNotNull($logged[0]['response_body']);
        self::assertNull($logged[0]['idempotency_key'], 'no key was sent');
    }

    public function testAQueryIsNotLoggedAsACommand(): void
    {
        $this->send('GET', '/admin/tipp-years', $this->admin);

        self::assertSame([], $this->db->fetchAll('SELECT * FROM command_log'));
    }

    // --- OPS-03: audit trail ---

    public function testTheHistoryOfAnAggregateIsReadable(): void
    {
        $created = $this->createTippYearOverHttp();
        $tippYearId = $created->data()['resourceId'];

        $audit = $this->send('GET', "/admin/audit/tipp_year/$tippYearId", $this->admin);

        self::assertSame(200, $audit->statusCode());
        self::assertSame('tipp_year', $audit->data()['aggregateType']);
        self::assertSame("tipp_year-$tippYearId", $audit->data()['streamId']);
        self::assertSame(1, $audit->data()['version']);

        $events = $audit->data()['events'];
        self::assertIsArray($events);
        self::assertCount(1, $events);
        self::assertSame('tipp_year.created', $events[0]['eventType']);
        self::assertSame('Tippjahr 2026', $events[0]['data']['name']);
    }

    public function testTheAuditTrailShowsWhyARowWasReplaced(): void
    {
        $created = $this->createTippYearOverHttp();
        $tippYearId = $created->data()['resourceId'];

        $period = $this->send('POST', "/admin/tipp-years/$tippYearId/bet-periods", $this->admin, [
            'name' => '2026 gesamt',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
        ]);
        $betPeriodId = $period->data()['resourceId'];

        $this->send('POST', "/admin/tipp-years/$tippYearId/members", $this->admin, ['participantId' => 7]);

        $assigned = $this->send('PUT', '/admin/participants/7/bet-row', $this->admin, [
            'betPeriodId' => $betPeriodId,
            'numbers' => [3, 12, 19, 27, 33, 45],
        ]);
        $betRowId = $assigned->data()['resourceId'];

        $this->send('PUT', '/admin/participants/7/bet-row', $this->admin, [
            'betPeriodId' => $betPeriodId,
            'numbers' => [1, 2, 3, 4, 5, 6],
            'replaceReason' => 'wrong slip transcribed',
        ]);

        $audit = $this->send('GET', "/admin/audit/bet_row/$betRowId", $this->admin);
        $events = $audit->data()['events'];

        self::assertIsArray($events);
        self::assertCount(2, $events);
        self::assertSame('bet_row.assigned', $events[0]['eventType']);
        self::assertSame('bet_row.replaced', $events[1]['eventType']);

        // The reason exists nowhere else - the read model only has the result
        self::assertSame('wrong slip transcribed', $events[1]['data']['reason']);
        self::assertSame([3, 12, 19, 27, 33, 45], $events[1]['data']['previous_numbers']);
    }

    public function testAnAggregateWithoutHistoryIs404(): void
    {
        self::assertSame(404, $this->send('GET', '/admin/audit/tipp_year/999', $this->admin)->statusCode());
    }

    public function testAnUnknownAggregateTypeIs400(): void
    {
        $response = $this->send('GET', '/admin/audit/unicorn/1', $this->admin);

        self::assertSame(400, $response->statusCode());
        self::assertStringContainsString('Unknown aggregate type', (string) $response->data()['message']);
    }

    public function testTheAuditTrailIsAdminOnly(): void
    {
        self::assertSame(403, $this->send('GET', '/admin/audit/tipp_year/1', $this->token(7))->statusCode());
    }

    // --- OPS-04: projections ---

    public function testProjectionsReportTheirLag(): void
    {
        $this->createTippYearOverHttp();

        $response = $this->send('GET', '/admin/projections', $this->admin);

        self::assertSame(200, $response->statusCode());
        $projections = $response->data()['projections'];
        self::assertIsArray($projections);
        self::assertCount(7, $projections);

        $byName = [];
        foreach ($projections as $projection) {
            $byName[$projection['name']] = $projection;
        }

        // The repository projected the tipp year as it wrote it and recorded
        // how far it got, so nothing is outstanding. This used to assert the
        // opposite - projection_state was only ever moved by a rebuild, which
        // made the endpoint report a backlog that grew with every command while
        // the data was current. A monitor that always cries wolf is worse than
        // none, because a projection that really stops being written looks the
        // same. ProjectionStatusTest covers the other half: that a genuine
        // backlog is still reported.
        self::assertSame(0, $byName['tipp_year_read_model']['lag']);
        self::assertTrue($byName['tipp_year_read_model']['upToDate']);

        // Nothing happened that the fee projection cares about
        self::assertSame(0, $byName['fee_read_model']['lag']);
        self::assertTrue($byName['fee_read_model']['upToDate']);
    }

    public function testRebuildingOverHttp(): void
    {
        $this->createTippYearOverHttp();

        $response = $this->send('POST', '/admin/projections/tipp_year_read_model/rebuild', $this->admin);

        self::assertSame(200, $response->statusCode());
        $rebuilt = $response->data()['rebuilt'];
        self::assertIsArray($rebuilt);

        // Everything that cascades off tipp_year comes back with it
        self::assertSame('tipp_year_read_model', $rebuilt[0]['name']);
        self::assertGreaterThan(1, count($rebuilt));

        foreach ($rebuilt as $projection) {
            self::assertSame(0, $projection['lag']);
        }

        // And the data survived
        self::assertCount(1, $this->send('GET', '/admin/tipp-years', $this->admin)->data()['tippYears']);
    }

    public function testRebuildingAll(): void
    {
        $this->createTippYearOverHttp();

        $response = $this->send('POST', '/admin/projections/all/rebuild', $this->admin);

        self::assertSame(200, $response->statusCode());
        self::assertCount(7, $response->data()['rebuilt']);
    }

    public function testRebuildingAnUnknownProjectionIs404(): void
    {
        self::assertSame(
            404,
            $this->send('POST', '/admin/projections/nope_read_model/rebuild', $this->admin)->statusCode()
        );
    }

    public function testProjectionsAreAdminOnly(): void
    {
        self::assertSame(403, $this->send('GET', '/admin/projections', $this->token(7))->statusCode());
        self::assertSame(
            403,
            $this->send('POST', '/admin/projections/all/rebuild', $this->token(7))->statusCode()
        );
    }

    public function testARebuildIsNotLoggedAsACommand(): void
    {
        $this->createTippYearOverHttp();
        $this->send('POST', '/admin/projections/all/rebuild', $this->admin);

        $logged = $this->db->fetchAll('SELECT * FROM command_log');

        self::assertCount(1, $logged, 'only the tipp year command, not the rebuild');
    }
}
