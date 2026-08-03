<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Http;

use BettingGame\Domain\ValueObject\ParticipantStatus;

/**
 * E1-01 over HTTP, which is where the point of it shows.
 *
 * The account that registers carries **no `participant_id` claim** - it never
 * will, because nobody is going to type an id into the realm. Everything below
 * therefore also tests the one thing that makes self-registration work: the
 * kernel recognising the account by its `sub` on every later request.
 */
final class RegistrationApiTest extends HttpTestCase
{
    private const SUBJECT = '3f1c8b64-0a1e-4f2b-9a77-account';

    public function testAnAccountRegistersAndIsToldItIsWaiting(): void
    {
        $account = $this->accountToken(self::SUBJECT);

        $registered = $this->send('POST', '/registrations', $account, [
            'displayName' => 'Erika Mustermann',
        ]);

        self::assertSame(202, $registered->statusCode());

        $mine = $this->send('GET', '/registrations/me', $account);

        self::assertSame(200, $mine->statusCode());
        self::assertTrue($mine->data()['registered']);
        self::assertSame(ParticipantStatus::PENDING, $mine->data()['status']);
        self::assertSame('Erika Mustermann', $mine->data()['displayName']);
    }

    public function testAnAccountThatHasNotRegisteredIsToldThatToo(): void
    {
        // Not a 404: the question is legitimate for anyone signed in, and this
        // is what the interface needs in order to offer the form.
        $mine = $this->send('GET', '/registrations/me', $this->accountToken('never-registered'));

        self::assertSame(200, $mine->statusCode());
        self::assertFalse($mine->data()['registered']);
    }

    public function testTheApprovedAccountReachesItsOwnDataWithoutAClaim(): void
    {
        $account = $this->accountToken(self::SUBJECT);
        $admin = $this->token(1, ['admin']);

        $registered = $this->send('POST', '/registrations', $account, [
            'displayName' => 'Erika Mustermann',
        ]);
        $participantId = $registered->data()['resourceId'];
        self::assertIsInt($participantId);

        self::assertSame(202, $this->send(
            'PUT',
            "/admin/participants/$participantId/status",
            $admin,
            ['isActive' => true]
        )->statusCode());

        // The token still carries no participant_id. Reaching one's own data
        // all the same is the whole point of E1-01 - before it, this needed an
        // attribute set by hand in Keycloak.
        $fees = $this->send('GET', "/participants/$participantId/fees", $account);

        self::assertSame(200, $fees->statusCode());
        self::assertSame([], $fees->data()['fees']);
    }

    public function testOneAccountStaysOneParticipant(): void
    {
        $account = $this->accountToken(self::SUBJECT);

        $this->send('POST', '/registrations', $account, ['displayName' => 'Erika Mustermann']);

        $second = $this->send('POST', '/registrations', $account, ['displayName' => 'Erika Nochmal']);

        self::assertSame(409, $second->statusCode());
        self::assertStringContainsString('waiting for approval', $second->data()['message']);
    }

    public function testSomebodyElsesDataStaysOutOfReach(): void
    {
        // B-16 does not soften because the identity came from the subject
        // rather than from a claim.
        $account = $this->accountToken(self::SUBJECT);
        $this->givenParticipant(7, 'Anna');

        $this->send('POST', '/registrations', $account, ['displayName' => 'Erika Mustermann']);

        $response = $this->send('GET', '/participants/7/fees', $account);

        self::assertSame(403, $response->statusCode());
    }

    public function testRegisteringNeedsALogin(): void
    {
        $response = $this->send('POST', '/registrations', null, [
            'displayName' => 'Erika Mustermann',
        ]);

        self::assertSame(401, $response->statusCode());
    }

    public function testTheRegistrationTakesTheAccountFromTheTokenNotTheBody(): void
    {
        // A caller naming somebody else's account would be occupying it before
        // they get there. The body is not read for it at all.
        $account = $this->accountToken(self::SUBJECT);

        $this->send('POST', '/registrations', $account, [
            'displayName' => 'Erika Mustermann',
            'keycloakSubject' => 'somebody-else',
        ]);

        self::assertNotNull($this->participants->findByKeycloakSubject(self::SUBJECT));
        self::assertNull($this->participants->findByKeycloakSubject('somebody-else'));
    }
}
