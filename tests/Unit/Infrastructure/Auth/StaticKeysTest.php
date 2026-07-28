<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Infrastructure\Auth;

use BettingGame\Infrastructure\Auth\KeyUnavailableException;
use BettingGame\Infrastructure\Auth\StaticKeys;
use BettingGame\Tests\Support\SigningKey;
use PHPUnit\Framework\TestCase;

final class StaticKeysTest extends TestCase
{
    public function testAKeySetCanBeGivenAsJson(): void
    {
        $keys = StaticKeys::from(SigningKey::shared()->jwks());

        self::assertSame([SigningKey::shared()->kid], $keys->keys()->kids());
    }

    public function testAKeySetCanBeGivenAsAFilePath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'jwks');
        self::assertIsString($path);
        file_put_contents($path, SigningKey::shared()->jwks());

        try {
            self::assertSame([SigningKey::shared()->kid], StaticKeys::from($path)->keys()->kids());
        } finally {
            unlink($path);
        }
    }

    /**
     * Configured but unusable is an outage, not an empty key set: an empty set
     * rejects every token, which looks exactly like a deployment where nobody
     * can log in for no visible reason. This fails at boot instead.
     */
    public function testAnUnusableKeySetFailsLoudly(): void
    {
        $this->expectException(KeyUnavailableException::class);
        new StaticKeys('{"keys":[]}');
    }

    public function testAMissingFileFailsLoudly(): void
    {
        $this->expectException(KeyUnavailableException::class);
        $this->expectExceptionMessage('cannot be read');

        StaticKeys::from('/no/such/jwks.json');
    }

    public function testRefreshingHasNowhereToGoAndSaysSoByStayingPut(): void
    {
        $keys = StaticKeys::from(SigningKey::shared()->jwks());

        self::assertSame($keys->keys()->kids(), $keys->refresh()->kids());
    }
}
