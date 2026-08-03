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

    // --- B-18: the tipp year lifecycle over HTTP ---

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
            'durationWeeks' => 4,
            'drawDays' => 'both',
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

        // B-23: the second statement was read class by class, so no total comes
        // with it - the amount per row and the rows make one.
        $second = $this->send('POST', '/admin/draws', $admin, [
            'tippYearId' => $tippYearId,
            'drawDate' => '2026-01-10',
            'numbers' => [3, 12, 19, 33, 44, 45],
            'superzahl' => 7,
        ]);
        self::assertSame(202, $second->statusCode());
        $secondId = $second->data()['resourceId'];
        self::assertIsInt($secondId);

        // The single row hits five numbers plus the Superzahl, so class 3 is
        // the one it is in; class 8 is on the statement and reaches nobody.
        self::assertSame(202, $this->send('PUT', "/admin/draws/$secondId/winnings", $admin, [
            'winningClasses' => [
                ['winningClass' => 3, 'amountPerRow' => 15.50],
                ['winningClass' => 8, 'amountPerRow' => 5.20],
            ],
        ])->statusCode());

        // The participant can now see it all
        $participant = $this->token(7);

        $betRow = $this->send('GET', '/participants/7/bet-row?betPeriodId=' . $betPeriodId, $participant);
        self::assertSame(200, $betRow->statusCode());
        self::assertSame([3, 12, 19, 27, 33, 45], $betRow->data()['numbers']);

        $fees = $this->send('GET', '/participants/7/fees', $participant);
        self::assertSame(200, $fees->statusCode());
        self::assertSame(9.60, $fees->data()['summary']['totalOpen'], '1 row x 8 draws x 1.20');

        $draws = $this->send('GET', "/tipp-years/$tippYearId/draws", $participant);
        self::assertSame(200, $draws->statusCode());
        self::assertSame(
            138.95,
            $draws->data()['totalWinnings'],
            '123.45 plus one row of class 3 at 15.50'
        );
    }

    /**
     * B-28: correcting a draw over HTTP, and the door closing behind it.
     *
     * The rules themselves are pinned in CorrectDrawTest; what this adds is the
     * route, the input reading and the status codes - including that the refusal
     * arrives in the caller's language.
     */
    public function testADrawCanBeCorrectedUntilItsWinningsAreRecorded(): void
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
        self::assertIsInt($tippYearId);

        $period = $this->send('POST', "/admin/tipp-years/$tippYearId/bet-periods", $admin, [
            'name' => '2026 gesamt',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
        ]);
        $this->send('POST', "/admin/tipp-years/$tippYearId/members", $admin, ['participantId' => 7]);
        $this->send('PUT', '/admin/participants/7/bet-row', $admin, [
            'betPeriodId' => $period->data()['resourceId'],
            'numbers' => [3, 12, 19, 27, 33, 45],
        ]);
        $this->startTippYear($tippYearId);
        $this->send('POST', "/admin/tipp-years/$tippYearId/tickets", $admin, [
            'periodStart' => '2026-01-01',
            'durationWeeks' => 4,
            'drawDays' => 'both',
            'superzahl' => 7,
        ]);

        $draw = $this->send('POST', '/admin/draws', $admin, [
            'tippYearId' => $tippYearId,
            'drawDate' => '2026-01-07',
            'numbers' => [3, 12, 19, 27, 40, 41],
            'superzahl' => 7,
        ]);
        $drawId = $draw->data()['resourceId'];
        self::assertIsInt($drawId);

        // The 41 should have been the 33
        $corrected = $this->send('PUT', "/admin/draws/$drawId", $admin, [
            'drawDate' => '2026-01-07',
            'numbers' => [3, 12, 19, 27, 33, 41],
            'superzahl' => 7,
        ]);

        self::assertSame(202, $corrected->statusCode());

        $draws = $this->send('GET', "/tipp-years/$tippYearId/draws", $this->token(7));
        self::assertSame([3, 12, 19, 27, 33, 41], $draws->data()['draws'][0]['numbers']);

        // B-26: which slip took part, by the number printed on it
        $ticket = $draws->data()['draws'][0]['ticket'];
        self::assertSame(7, $ticket['superzahl'], 'the ticket Superzahl, not the drawn one');
        self::assertArrayHasKey('lotteryReference', $ticket);

        self::assertSame(202, $this->send('PUT', "/admin/draws/$drawId/winnings", $admin, [
            'totalAmount' => 25.00,
        ])->statusCode());

        $refused = $this->send('PUT', "/admin/draws/$drawId", $admin, [
            'drawDate' => '2026-01-07',
            'numbers' => [1, 2, 3, 4, 5, 6],
            'superzahl' => 7,
        ], ['Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertSame(409, $refused->statusCode());
        self::assertStringStartsWith(
            'Diese Ziehung ist bereits ausgewertet',
            $refused->data()['message']
        );
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

        // B-06 finds the existing row itself rather than letting
        // uk_participant_period fire, so this is a sentence the handler wrote
        self::assertStringStartsWith(
            'This participant already has a row for this bet period.',
            $conflict->data()['message']
        );

        // The same rule, asked for in German
        $german = $this->send(
            'PUT',
            '/admin/participants/7/bet-row',
            $admin,
            ['betPeriodId' => $betPeriodId, 'numbers' => [1, 2, 3, 4, 5, 6]],
            ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8']
        );

        self::assertSame(409, $german->statusCode());
        self::assertStringStartsWith(
            'Dieser Teilnehmer hat für diese Tippperiode bereits eine Reihe.',
            $german->data()['message']
        );

        // With a reason it goes through
        self::assertSame(202, $this->send('PUT', '/admin/participants/7/bet-row', $admin, [
            'betPeriodId' => $betPeriodId,
            'numbers' => [1, 2, 3, 4, 5, 6],
            'replaceReason' => 'wrong slip transcribed',
        ])->statusCode());
    }

    /**
     * Not every rule is checked before the write. A duplicate draw date is left
     * to `uk_draw_date`, so the database is what rejects it - and what came
     * back was the driver's own words, naming the key and the values that
     * collided, straight into the browser.
     */
    public function testAUniqueKeyRejectingAWriteDoesNotReturnTheDriversMessage(): void
    {
        $admin = $this->token(1, ['admin']);

        $year = $this->send('POST', '/admin/tipp-years', $admin, [
            'name' => 'Tippjahr 2026',
            'startDate' => '2026-01-01',
            'endDate' => '2026-12-31',
            'ticketCostPerRow' => 1.20,
        ]);
        $tippYearId = $year->data()['resourceId'];

        $draw = ['tippYearId' => $tippYearId, 'drawDate' => '2026-01-07',
                 'numbers' => [3, 12, 19, 27, 40, 41], 'superzahl' => 7];

        self::assertSame(202, $this->send('POST', '/admin/draws', $admin, $draw)->statusCode());

        $conflict = $this->send('POST', '/admin/draws', $admin, $draw);
        $message = $conflict->data()['message'];

        self::assertSame(409, $conflict->statusCode());
        self::assertSame('A draw has already been recorded for this date', $message);
        self::assertStringNotContainsString('SQLSTATE', $message);
        self::assertStringNotContainsString('uk_draw_date', $message);

        $german = $this->send('POST', '/admin/draws', $admin, $draw, ['Accept-Language' => 'de']);

        self::assertSame(
            'Für dieses Datum ist bereits eine Ziehung eingetragen',
            $german->data()['message']
        );
    }

    public function testAnErrorWithoutATranslationStaysEnglish(): void
    {
        // French has no catalogue, so the documented fallback applies
        $response = $this->send(
            'GET',
            '/tipp-years/999/draws',
            $this->token(7),
            [],
            ['Accept-Language' => 'fr-FR,fr;q=0.9']
        );

        self::assertSame(404, $response->statusCode());
        self::assertSame('Tipp year 999 does not exist', $response->data()['message']);
    }

    public function testTheNumbersInAMessageSurviveTheTranslation(): void
    {
        $response = $this->send(
            'GET',
            '/tipp-years/999/draws',
            $this->token(7),
            [],
            ['Accept-Language' => 'de']
        );

        self::assertSame('Das Tippjahr 999 gibt es nicht', $response->data()['message']);
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

    /** B-25 */
    public function testTheAdminRenamesAParticipantAndSetsThemInactive(): void
    {
        $admin = $this->token(1, ['admin']);
        $this->givenParticipant(7, 'Erika Musterman');

        self::assertSame(202, $this->send('PUT', '/admin/participants/7', $admin, [
            'displayName' => 'Erika Mustermann',
        ])->statusCode());

        self::assertSame(202, $this->send('PUT', '/admin/participants/7/status', $admin, [
            'isActive' => false,
        ])->statusCode());

        $roster = $this->send('GET', '/admin/participants', $admin);
        self::assertSame(200, $roster->statusCode());
        self::assertSame('Erika Mustermann', $roster->data()['participants'][0]['displayName']);
        self::assertSame('inactive', $roster->data()['participants'][0]['status']);
        self::assertFalse($roster->data()['participants'][0]['isActive']);

        // The same list as a picker asks for it
        $active = $this->send('GET', '/admin/participants?status=active', $admin);
        self::assertSame([], $active->data()['participants'], 'nobody is still playing');

        $nonsense = $this->send('GET', '/admin/participants?status=irgendwas', $admin);
        self::assertSame(400, $nonsense->statusCode(), 'an unknown filter is not an empty roster');
    }

    /** B-25: the status has to be stated, not defaulted into deactivation. */
    public function testSettingAParticipantStatusWithoutSayingWhichIs400(): void
    {
        $admin = $this->token(1, ['admin']);
        $this->givenParticipant(7, 'Erika Mustermann');

        $response = $this->send('PUT', '/admin/participants/7/status', $admin, []);

        self::assertSame(400, $response->statusCode());
        self::assertStringContainsString('isActive', $response->data()['message']);
    }

    /** B-25 is admin-only, like the rest of the roster (B-16). */
    public function testAParticipantCannotRenameAnybody(): void
    {
        $this->givenParticipant(7, 'Erika Mustermann');

        $response = $this->send('PUT', '/admin/participants/7', $this->token(7), [
            'displayName' => 'Wer Anders',
        ]);

        self::assertSame(403, $response->statusCode());
    }
}
