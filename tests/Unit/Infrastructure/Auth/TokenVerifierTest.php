<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Infrastructure\Auth;

use BettingGame\Infrastructure\Auth\Base64Url;
use BettingGame\Infrastructure\Auth\InvalidTokenException;
use BettingGame\Infrastructure\Auth\JwkSet;
use BettingGame\Infrastructure\Auth\KeySource;
use BettingGame\Infrastructure\Auth\KeyUnavailableException;
use BettingGame\Infrastructure\Auth\StaticKeys;
use BettingGame\Infrastructure\Auth\TokenVerifier;
use BettingGame\Tests\Support\SigningKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The gap this closes: the application used to read the claims out of a token
 * and believe them. Every test below that expects a rejection describes a token
 * the old code accepted.
 */
final class TokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://auth.example.test/realms/betting-game';

    private SigningKey $keycloak;

    protected function setUp(): void
    {
        $this->keycloak = SigningKey::shared();
    }

    private function verifier(
        ?KeySource $keys = null,
        ?string $audience = null,
        int $leeway = 30
    ): TokenVerifier {
        return new TokenVerifier(
            keys: $keys ?? new StaticKeys($this->keycloak->jwks()),
            issuer: self::ISSUER,
            audience: $audience,
            leewaySeconds: $leeway
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return [
            'iss' => self::ISSUER,
            'sub' => 'ab12',
            'exp' => time() + 300,
            'iat' => time(),
            'participant_id' => 7,
            'preferred_username' => 'tester',
            'realm_access' => ['roles' => ['user']],
            ...$overrides,
        ];
    }

    public function testATokenSignedByTheRealmIsAccepted(): void
    {
        $claims = $this->verifier()->verify($this->keycloak->token($this->claims()));

        self::assertSame(7, $claims['participant_id']);
        self::assertSame('tester', $claims['preferred_username']);
    }

    /**
     * The whole point. Anyone can write these claims; only Keycloak can sign
     * them.
     */
    public function testATokenNobodySignedIsRejected(): void
    {
        $forged = $this->keycloak->unsignedToken($this->claims([
            'participant_id' => 1,
            'realm_access' => ['roles' => ['admin']],
        ]));

        $this->expectException(InvalidTokenException::class);
        $this->verifier()->verify($forged);
    }

    public function testATokenSignedWithSomebodyElsesKeyIsRejected(): void
    {
        // A real, valid RSA signature - just not by the realm. The attacker
        // even publishes a kid that matches, which is why the key has to come
        // from our key set and never from the token.
        $attacker = SigningKey::generate($this->keycloak->kid);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('the signature does not match');

        $this->verifier()->verify($attacker->token($this->claims()));
    }

    /**
     * alg: none says "this token needs no signature". A verifier that takes the
     * algorithm from the token it is checking will agree.
     */
    public function testAlgNoneIsRejected(): void
    {
        $header = Base64Url::encode((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = Base64Url::encode((string) json_encode($this->claims([
            'realm_access' => ['roles' => ['admin']],
        ])));

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('none is not an accepted algorithm');

        $this->verifier()->verify("$header.$payload.");
    }

    /**
     * The same claim with bytes in the signature slot, so the refusal cannot be
     * credited to the signature being empty.
     */
    public function testAlgNoneWithAPlausibleLookingSignatureIsRejected(): void
    {
        $header = Base64Url::encode((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = Base64Url::encode((string) json_encode($this->claims()));
        $signature = Base64Url::encode('anything at all');

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('none is not an accepted algorithm');

        $this->verifier()->verify("$header.$payload.$signature");
    }

    /**
     * Algorithm confusion: the attacker signs with HMAC, using as the shared
     * secret the RSA public key that the realm publishes to the world. A
     * verifier that trusts the header's alg checks an HMAC it can reproduce.
     */
    public function testAnHmacTokenSignedWithThePublicKeyIsRejected(): void
    {
        $header = Base64Url::encode((string) json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
            'kid' => $this->keycloak->kid,
        ]));
        $payload = Base64Url::encode((string) json_encode($this->claims([
            'realm_access' => ['roles' => ['admin']],
        ])));

        $signature = Base64Url::encode(
            hash_hmac('sha256', "$header.$payload", $this->keycloak->publicKeyPem(), true)
        );

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('HS256 is not an accepted algorithm');

        $this->verifier()->verify("$header.$payload.$signature");
    }

    /**
     * The symmetric algorithms are not merely absent from the default list -
     * they cannot be configured in, and the refusal happens at boot rather than
     * on the request that would have been forged.
     */
    public function testASymmetricAlgorithmCannotBeConfigured(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported signing algorithm: HS256');

        new TokenVerifier(
            keys: new StaticKeys($this->keycloak->jwks()),
            issuer: self::ISSUER,
            algorithms: ['RS256', 'HS256']
        );
    }

    public function testAChangedPayloadInvalidatesTheSignature(): void
    {
        $token = $this->keycloak->token($this->claims(['participant_id' => 7]));

        [$header, , $signature] = explode('.', $token);
        $tampered = Base64Url::encode((string) json_encode($this->claims(['participant_id' => 1])));

        $this->expectException(InvalidTokenException::class);
        $this->verifier()->verify("$header.$tampered.$signature");
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('expired');

        $this->verifier()->verify($this->keycloak->token($this->claims(['exp' => time() - 3600])));
    }

    public function testATokenWithoutAnExpiryIsRejected(): void
    {
        $claims = $this->claims();
        unset($claims['exp']);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('no expiry');

        $this->verifier()->verify($this->keycloak->token($claims));
    }

    /**
     * Clocks drift. A token that expired a second ago is far more likely to be
     * our clock than an attack, so the leeway covers it - and a token that
     * expired an hour ago is still gone.
     */
    public function testTheLeewayCoversASmallClockDifference(): void
    {
        $justExpired = $this->keycloak->token($this->claims(['exp' => time() - 5]));

        self::assertSame(self::ISSUER, $this->verifier(leeway: 30)->verify($justExpired)['iss']);

        $this->expectException(InvalidTokenException::class);
        $this->verifier(leeway: 0)->verify($justExpired);
    }

    public function testATokenThatIsNotValidYetIsRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('not valid yet');

        $this->verifier()->verify($this->keycloak->token($this->claims(['nbf' => time() + 600])));
    }

    /**
     * A second realm on the same Keycloak has its own keys, so this could not
     * reach a valid signature - but a shared key set, or a realm we simply do
     * not serve, could. The issuer is checked on its own merits.
     */
    public function testATokenFromAnotherRealmIsRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('issued by');

        $this->verifier()->verify($this->keycloak->token($this->claims([
            'iss' => 'https://auth.example.test/realms/somewhere-else',
        ])));
    }

    public function testAnUnknownKidIsRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('no key for kid');

        $this->verifier()->verify($this->keycloak->token($this->claims(), ['kid' => 'made-up']));
    }

    /**
     * Key rotation. Keycloak signs with the new key the instant it rotates, so
     * a kid we do not know has to send us back to the key set once - otherwise
     * every rotation is an outage until the cache happens to expire.
     */
    public function testAnUnknownKidTriggersOneRefresh(): void
    {
        $rotated = SigningKey::generate('key-after-rotation');
        $keys = new class ($this->keycloak, $rotated) implements KeySource {
            public int $refreshes = 0;

            public function __construct(private SigningKey $before, private SigningKey $after)
            {
            }

            public function keys(): JwkSet
            {
                return JwkSet::fromJson($this->before->jwks());
            }

            public function refresh(): JwkSet
            {
                $this->refreshes++;

                return JwkSet::fromJson($this->after->jwks());
            }
        };

        $claims = $this->verifier($keys)->verify($rotated->token($this->claims()));

        self::assertSame(7, $claims['participant_id']);
        self::assertSame(1, $keys->refreshes, 'exactly one refetch, not one per key');
    }

    /**
     * A key published for RS256 must not be borrowed to check an RS512
     * signature. The realm said what the key is for.
     */
    public function testAKeyIsNotUsedForAnAlgorithmItWasNotPublishedFor(): void
    {
        $verifier = new TokenVerifier(
            keys: new StaticKeys($this->keycloak->jwks('RS256')),
            issuer: self::ISSUER,
            algorithms: ['RS256', 'RS512']
        );

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('no key for kid');

        $verifier->verify($this->keycloak->token($this->claims(), [], 'RS512'));
    }

    public function testTheAudienceIsCheckedWhenConfigured(): void
    {
        $verifier = $this->verifier(audience: 'betting-game-api');

        $accepted = $verifier->verify($this->keycloak->token($this->claims([
            'aud' => ['account', 'betting-game-api'],
        ])));
        self::assertSame('ab12', $accepted['sub']);

        $this->expectException(InvalidTokenException::class);
        $this->expectExceptionMessage('not addressed to betting-game-api');

        $verifier->verify($this->keycloak->token($this->claims(['aud' => 'some-other-client'])));
    }

    public function testTheAudienceIsIgnoredWhenNotConfigured(): void
    {
        $claims = $this->verifier()->verify($this->keycloak->token($this->claims(['aud' => 'anything'])));

        self::assertSame('ab12', $claims['sub']);
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokensAreRejected(string $token): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->verifier()->verify($token);
    }

    /** @return array<string, array{string}> */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'one part' => ['abc'],
            'two parts' => ['abc.def'],
            'four parts' => ['a.b.c.d'],
            'header is not base64url' => ['not base64!.eyJhIjoxfQ.c2ln'],
            'header is not JSON' => [Base64Url::encode('nope') . '.eyJhIjoxfQ.c2ln'],
            'empty signature' => [Base64Url::encode('{"alg":"RS256"}') . '.eyJhIjoxfQ.'],
        ];
    }

    /**
     * When the keys cannot be had, a token is neither good nor bad - and saying
     * "bad" would send every client off to re-authenticate against the very
     * thing that is down.
     */
    public function testAnUnreachableKeySourceIsNotATokenProblem(): void
    {
        $keys = new class implements KeySource {
            public function keys(): JwkSet
            {
                throw new KeyUnavailableException('keycloak is down');
            }

            public function refresh(): JwkSet
            {
                throw new KeyUnavailableException('keycloak is down');
            }
        };

        $this->expectException(KeyUnavailableException::class);
        $this->verifier($keys)->verify($this->keycloak->token($this->claims()));
    }
}
