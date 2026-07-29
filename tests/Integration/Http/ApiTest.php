<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Http;

/**
 * The whole chain through the front controller: routing, authentication, the
 * role gate, the controller and the exception mapping.
 *
 * Everything below this has its own tests; what only shows up here is whether
 * a domain exception really becomes the status code the API documents, and
 * whether a route is reachable by someone who should not reach it.
 */
final class ApiTest extends HttpTestCase
{
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

    /**
     * B-15/B-16/B-17 rest entirely on this. Every rule above says "only this
     * participant" or "only an admin", and each one is worth exactly as much as
     * the signature check - the claims naming the participant and the role come
     * out of the token.
     *
     * Until the verifier existed this request succeeded.
     */
    public function testAForgedTokenReachesNothing(): void
    {
        $response = $this->send('GET', '/participants/7/bet-row', $this->forgedToken(7));

        self::assertSame(401, $response->statusCode());
    }

    public function testAForgedAdminTokenReachesNoAdminRoute(): void
    {
        $response = $this->send('GET', '/admin/tipp-years', $this->forgedToken(1, ['admin']));

        self::assertSame(401, $response->statusCode(), 'the admin role has to be granted, not claimed');
    }

    public function testAnExpiredTokenIs401(): void
    {
        $response = $this->send('GET', '/participants/7/bet-row', $this->expiredToken(7));

        self::assertSame(401, $response->statusCode());
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

    // --- B-18: der Lebenszyklus des Tippjahres über HTTP ---

    public function testAnAdminMovesATippYearThroughItsLifecycle(): void
    {
        $admin = $this->token(1, ['admin']);
        $tippYearId = $this->givenATippYear($admin);

        foreach (['running', 'closed', 'planned'] as $status) {
            $response = $this->send('PUT', "/admin/tipp-years/$tippYearId/status", $admin, [
                'status' => $status,
            ]);

            self::assertSame(202, $response->statusCode(), "moving to $status");
        }

        $years = $this->send('GET', '/admin/tipp-years', $admin)->data()['tippYears'];
        self::assertIsArray($years);
        self::assertSame('planned', $years[0]['status']);
    }

    public function testASecondRunningTippYearIs409(): void
    {
        $admin = $this->token(1, ['admin']);
        $first = $this->givenATippYear($admin);
        $second = $this->givenATippYear($admin, 'Tippjahr 2027', '2027-01-01', '2027-12-31');

        self::assertSame(202, $this->send('PUT', "/admin/tipp-years/$first/status", $admin, [
            'status' => 'running',
        ])->statusCode());

        $response = $this->send('PUT', "/admin/tipp-years/$second/status", $admin, [
            'status' => 'running',
        ]);

        self::assertSame(409, $response->statusCode());
        self::assertStringContainsString('still running', (string) $response->data()['message']);
    }

    public function testAnUnknownStatusIs400(): void
    {
        $admin = $this->token(1, ['admin']);
        $tippYearId = $this->givenATippYear($admin);

        $response = $this->send('PUT', "/admin/tipp-years/$tippYearId/status", $admin, [
            'status' => 'paused',
        ]);

        self::assertSame(400, $response->statusCode());
    }

    public function testChangingTheStatusRejectsANonAdmin(): void
    {
        $response = $this->send('PUT', '/admin/tipp-years/1/status', $this->token(7), [
            'status' => 'running',
        ]);

        self::assertSame(403, $response->statusCode());
    }

    private function givenATippYear(
        string $admin,
        string $name = 'Tippjahr 2026',
        string $start = '2026-01-01',
        string $end = '2026-12-31'
    ): int {
        $response = $this->send('POST', '/admin/tipp-years', $admin, [
            'name' => $name,
            'startDate' => $start,
            'endDate' => $end,
            'ticketCostPerRow' => 1.20,
        ]);

        self::assertSame(202, $response->statusCode());
        $id = $response->data()['resourceId'];
        self::assertIsInt($id);

        return $id;
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
