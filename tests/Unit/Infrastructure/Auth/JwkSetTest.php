<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Infrastructure\Auth;

use BettingGame\Infrastructure\Auth\Base64Url;
use BettingGame\Infrastructure\Auth\JwkSet;
use BettingGame\Tests\Support\SigningKey;
use PHPUnit\Framework\TestCase;

final class JwkSetTest extends TestCase
{
    /**
     * The conversion is the part that could quietly be wrong: a PEM that
     * OpenSSL merely fails to load would make every signature "invalid", a
     * subtly wrong one is worse. Signing something and verifying it against the
     * reconstructed key is the only assertion that settles it.
     */
    public function testAnRsaJwkBecomesAKeyOpenSslCanVerifyWith(): void
    {
        $signer = SigningKey::shared();
        $set = JwkSet::fromJson($signer->jwks());

        $key = $set->keyFor($signer->kid, 'RS256');
        self::assertNotNull($key);

        $token = $signer->token(['sub' => 'x']);
        [$header, $payload, $signature] = explode('.', $token);

        self::assertSame(
            1,
            openssl_verify(
                "$header.$payload",
                (string) Base64Url::decode($signature),
                $key->pem,
                OPENSSL_ALGO_SHA256
            )
        );
    }

    /**
     * A 2048-bit modulus always has its top bit set, so the DER INTEGER needs a
     * leading zero byte or it reads as negative. This is the one number in the
     * encoding that is easy to get wrong and hard to notice.
     */
    public function testTheReconstructedKeyMatchesOpenSslsOwnEncoding(): void
    {
        $signer = SigningKey::shared();
        $key = JwkSet::fromJson($signer->jwks())->keyFor($signer->kid, 'RS256');

        self::assertNotNull($key);
        self::assertSame(
            trim($signer->publicKeyPem()),
            trim($key->pem),
            'the PEM built from n and e should be byte-identical to OpenSSL\'s'
        );
    }

    /**
     * Keycloak publishes its RSA-OAEP encryption key in the same document.
     * Using it to check a signature would be a category error; it is skipped,
     * and the signing key beside it still works.
     */
    public function testEncryptionKeysAreSkipped(): void
    {
        $signing = SigningKey::shared();
        $encryption = SigningKey::generate('enc-key');

        $set = JwkSet::fromJson(SigningKey::jwksOf(
            $encryption->jwk('RSA-OAEP', 'enc'),
            $signing->jwk()
        ));

        self::assertSame([$signing->kid], $set->kids());
    }

    public function testAKeySetWeCannotReadIsEmptyRatherThanPermissive(): void
    {
        self::assertTrue(JwkSet::fromJson('not json')->isEmpty());
        self::assertTrue(JwkSet::fromJson('{}')->isEmpty());
        self::assertTrue(JwkSet::fromJson('{"keys":[]}')->isEmpty());
        self::assertTrue(JwkSet::fromJson('{"keys":[{"kty":"EC","kid":"a","crv":"P-256"}]}')->isEmpty());
        self::assertTrue(JwkSet::fromJson('{"keys":[{"kty":"RSA","kid":"a"}]}')->isEmpty());
    }

    public function testAKeyWithoutAKidIsResolvedOnlyWhenItIsTheOnlyOne(): void
    {
        $one = SigningKey::shared();
        $set = JwkSet::fromJson($one->jwks());

        self::assertNotNull($set->keyFor(null, 'RS256'));

        $two = JwkSet::fromJson(SigningKey::jwksOf(
            $one->jwk(),
            SigningKey::generate('second')->jwk()
        ));

        self::assertNull(
            $two->keyFor(null, 'RS256'),
            'with a choice to make, guessing is worse than refusing'
        );
    }

    public function testAKeyWithoutADeclaredAlgorithmServesAnyAcceptedOne(): void
    {
        $signer = SigningKey::shared();

        $jwk = $signer->jwk();
        unset($jwk['alg']);

        $set = JwkSet::fromJson(SigningKey::jwksOf($jwk));

        self::assertNotNull($set->keyFor($signer->kid, 'RS256'));
        self::assertNotNull($set->keyFor($signer->kid, 'RS512'));
    }
}
