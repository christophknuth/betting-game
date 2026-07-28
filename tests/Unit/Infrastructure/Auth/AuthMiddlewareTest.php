<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Infrastructure\Auth;

use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Infrastructure\Auth\JwkSet;
use BettingGame\Infrastructure\Auth\KeycloakService;
use BettingGame\Infrastructure\Auth\KeySource;
use BettingGame\Infrastructure\Auth\KeyUnavailableException;
use BettingGame\Infrastructure\Auth\StaticKeys;
use BettingGame\Infrastructure\Auth\TokenVerifier;
use BettingGame\Presentation\Http\Request;
use BettingGame\Tests\Support\SigningKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AuthMiddlewareTest extends TestCase
{
    private const ISSUER = 'https://auth.example.test/realms/betting-game';

    private function middleware(?KeySource $keys = null): AuthMiddleware
    {
        $verifier = new TokenVerifier(
            keys: $keys ?? new StaticKeys(SigningKey::shared()->jwks()),
            issuer: self::ISSUER
        );

        return new AuthMiddleware(new KeycloakService($verifier), new NullLogger());
    }

    private function request(?string $token): Request
    {
        return new Request(
            'GET',
            '/participants/7/bet-row',
            $token === null ? [] : ['AUTHORIZATION' => "Bearer $token"],
            [],
            null
        );
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return SigningKey::shared()->token([
            'iss' => self::ISSUER,
            'sub' => 'ab12',
            'exp' => time() + 300,
            'participant_id' => 7,
            'preferred_username' => 'tester',
            'realm_access' => ['roles' => ['user']],
            ...$overrides,
        ]);
    }

    public function testAValidTokenPopulatesTheRequest(): void
    {
        $request = $this->request($this->token(['realm_access' => ['roles' => ['user', 'admin']]]));

        self::assertNull($this->middleware()->handle($request));
        self::assertTrue($request->attribute('authenticated'));
        self::assertSame(7, $request->attribute('participant_id'));
        self::assertSame('tester', $request->attribute('username'));
        self::assertSame(['user', 'admin'], $request->attribute('roles'));
        self::assertSame('ab12', $request->attribute('subject'));
    }

    public function testAForgedTokenLeavesTheRequestAnonymous(): void
    {
        $forged = SigningKey::shared()->unsignedToken([
            'iss' => self::ISSUER,
            'exp' => time() + 300,
            'participant_id' => 1,
            'realm_access' => ['roles' => ['admin']],
        ]);

        $request = $this->request($forged);
        $response = $this->middleware()->handle($request);

        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode());
        self::assertNull($request->attribute('participant_id'), 'nothing from an unverified token');
    }

    /**
     * The client is told the token was refused, and no more. Which check it
     * failed is our validation logic, and describing it hands an attacker the
     * feedback loop for the next attempt.
     */
    public function testTheReasonForRejectionIsNotHandedToTheClient(): void
    {
        $response = $this->middleware()->handle($this->request($this->token(['exp' => time() - 7200])));

        self::assertNotNull($response);
        self::assertSame('Invalid or expired token', $response->data()['message']);
    }

    /**
     * Keycloak being unreachable is our outage, not a bad token. Answering 401
     * would tell every client to discard a perfectly good token and go
     * re-authenticate against the thing we already know is down.
     */
    public function testAnUnreachableKeycloakIs503(): void
    {
        $keys = new class implements KeySource {
            public function keys(): JwkSet
            {
                throw new KeyUnavailableException('connection refused');
            }

            public function refresh(): JwkSet
            {
                throw new KeyUnavailableException('connection refused');
            }
        };

        $response = $this->middleware($keys)->handle($this->request($this->token()));

        self::assertNotNull($response);
        self::assertSame(503, $response->statusCode());
    }

    public function testAMissingOrMalformedHeaderIs401(): void
    {
        $missing = $this->middleware()->handle($this->request(null));
        self::assertNotNull($missing);
        self::assertSame(401, $missing->statusCode());

        $notBearer = new Request('GET', '/', ['AUTHORIZATION' => 'Basic dXNlcjpwdw=='], [], null);
        $response = $this->middleware()->handle($notBearer);
        self::assertNotNull($response);
        self::assertSame(401, $response->statusCode());
    }

    public function testOptionalAuthenticationIgnoresATokenItCannotVerify(): void
    {
        $request = $this->request(SigningKey::generate('attacker')->token([
            'iss' => self::ISSUER,
            'exp' => time() + 300,
            'participant_id' => 1,
            'realm_access' => ['roles' => ['admin']],
        ]));

        $this->middleware()->handleOptional($request);

        self::assertNull($request->attribute('authenticated'));
        self::assertNull($request->attribute('roles'), 'optional must not mean unverified');
    }
}
