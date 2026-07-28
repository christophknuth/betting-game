<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Infrastructure\Auth;

use BettingGame\Infrastructure\Auth\HttpFetcher;
use BettingGame\Infrastructure\Auth\KeycloakKeys;
use BettingGame\Infrastructure\Auth\KeyUnavailableException;
use BettingGame\Tests\Support\SigningKey;
use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * What is worth testing here is the caching and the refresh throttle, not curl.
 */
final class KeycloakKeysTest extends TestCase
{
    private const URL = 'https://auth.example.test/realms/betting-game/protocol/openid-connect/certs';

    private function cache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $values = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
            {
                $this->values[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->values[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->values = [];

                return true;
            }

            /** @param iterable<string> $keys @return iterable<string, mixed> */
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                foreach ($keys as $key) {
                    yield $key => $this->get($key, $default);
                }
            }

            /** @param iterable<string, mixed> $values */
            public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl);
                }

                return true;
            }

            /** @param iterable<string> $keys */
            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->values[$key]);
            }
        };
    }

    /** @param list<string|null> $responses */
    private function fetcher(array $responses): HttpFetcher
    {
        return new class ($responses) implements HttpFetcher {
            public int $calls = 0;

            /** @param list<string|null> $responses */
            public function __construct(private array $responses)
            {
            }

            public function get(string $url): ?string
            {
                $response = $this->responses[$this->calls] ?? end($this->responses) ?: null;
                $this->calls++;

                return is_string($response) ? $response : null;
            }
        };
    }

    /**
     * This call sits in front of every authenticated request. Without the
     * cache, each one would wait on an outbound HTTP round trip to Keycloak
     * before it could even look at the token.
     */
    public function testTheKeySetIsFetchedOnceAndThenServedFromTheCache(): void
    {
        $jwks = SigningKey::shared()->jwks();
        $fetcher = $this->fetcher([$jwks]);
        $cache = $this->cache();

        $first = new KeycloakKeys(self::URL, $fetcher, $cache);
        $first->keys();

        // A second instance stands in for the next request in a new process:
        // the cache is what carries the keys across, not the object.
        $second = new KeycloakKeys(self::URL, $fetcher, $cache);
        self::assertSame(SigningKey::shared()->kid, $second->keys()->kids()[0]);

        self::assertSame(1, $fetcher->calls);
    }

    /**
     * The refresh trigger is a kid we do not recognise - and the kid comes out
     * of a token the caller wrote. Unthrottled, a stream of invented kids turns
     * our authentication path into an amplifier pointed at Keycloak.
     */
    public function testRefreshingIsThrottled(): void
    {
        $jwks = SigningKey::shared()->jwks();
        $fetcher = $this->fetcher([$jwks]);
        $keys = new KeycloakKeys(self::URL, $fetcher, $this->cache());

        $keys->keys();

        for ($i = 0; $i < 20; $i++) {
            $keys->refresh();
        }

        self::assertSame(2, $fetcher->calls, 'one initial fetch, then one refresh for the window');
    }

    /**
     * Signing keys rotate on the order of months and tokens expire within the
     * hour, so a key set from earlier today still verifies today's tokens.
     * Refusing every request for the length of a Keycloak outage would be the
     * worse failure.
     */
    public function testALastKnownKeySetSurvivesAnOutage(): void
    {
        $jwks = SigningKey::shared()->jwks();
        $cache = $this->cache();

        (new KeycloakKeys(self::URL, $this->fetcher([$jwks]), $cache, ttlSeconds: 300))->keys();

        // The fresh copy has aged out; Keycloak is now unreachable.
        $cache->delete('jwks_fresh_' . md5(self::URL));

        $keys = new KeycloakKeys(self::URL, $this->fetcher([null]), $cache);

        self::assertSame([SigningKey::shared()->kid], $keys->keys()->kids());
    }

    public function testNoKeysAndNoCacheIsAnOutageNotARejection(): void
    {
        $keys = new KeycloakKeys(self::URL, $this->fetcher([null]), $this->cache());

        $this->expectException(KeyUnavailableException::class);
        $keys->keys();
    }

    public function testAnUnreadableResponseIsTreatedAsNoKeys(): void
    {
        $keys = new KeycloakKeys(self::URL, $this->fetcher(['<html>gateway timeout</html>']), $this->cache());

        $this->expectException(KeyUnavailableException::class);
        $keys->keys();
    }

    public function testARotatedKeyIsPickedUpByRefreshing(): void
    {
        $rotated = SigningKey::generate('key-after-rotation');
        $fetcher = $this->fetcher([SigningKey::shared()->jwks(), $rotated->jwks()]);

        $keys = new KeycloakKeys(self::URL, $fetcher, $this->cache());

        self::assertSame([SigningKey::shared()->kid], $keys->keys()->kids());
        self::assertSame(['key-after-rotation'], $keys->refresh()->kids());
    }
}
