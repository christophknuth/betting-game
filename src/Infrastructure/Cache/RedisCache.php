<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;
use DateInterval;
use Redis;

/**
 * PSR-16 compliant Redis cache implementation
 * Recommended for production use
 * 
 * Requires: ext-redis
 */
final class RedisCache implements CacheInterface
{
    private Redis $redis;
    private string $prefix;
    private int $defaultTtl;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $prefix = 'betting_game:',
        int $defaultTtl = 3600,
        ?string $password = null,
        int $database = 0
    ) {
        $this->redis = new Redis();
        $this->redis->connect($host, $port);
        
        if ($password !== null) {
            $this->redis->auth($password);
        }
        
        $this->redis->select($database);
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $value = $this->redis->get($this->prefix . $key);
        
        if ($value === false) {
            return $default;
        }

        return unserialize($value);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $ttlSeconds = $this->normalizeTtl($ttl);
        $serialized = serialize($value);

        if ($ttlSeconds === null || $ttlSeconds === 0) {
            return $this->redis->set($this->prefix . $key, $serialized);
        }

        return $this->redis->setex($this->prefix . $key, $ttlSeconds, $serialized);
    }

    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return $this->redis->del($this->prefix . $key) > 0;
    }

    public function clear(): bool
    {
        // Clear only keys with our prefix
        $keys = $this->redis->keys($this->prefix . '*');
        
        if (empty($keys)) {
            return true;
        }

        return $this->redis->del($keys) > 0;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $prefixedKeys = [];
        
        foreach ($keys as $key) {
            $this->validateKey($key);
            $prefixedKeys[] = $this->prefix . $key;
        }

        if (empty($prefixedKeys)) {
            return true;
        }

        return $this->redis->del($prefixedKeys) > 0;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return $this->redis->exists($this->prefix . $key) > 0;
    }

    /**
     * Get Redis connection info
     */
    public function getConnectionInfo(): array
    {
        return $this->redis->info('server');
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        return $this->redis->info('stats');
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new CacheInvalidArgumentException('Cache key cannot be empty');
        }

        if (preg_match('/[{}()\/@:]/', $key)) {
            throw new CacheInvalidArgumentException('Cache key contains reserved characters');
        }
    }

    private function normalizeTtl(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }

        if ($ttl instanceof DateInterval) {
            $now = new \DateTimeImmutable();
            $future = $now->add($ttl);
            return $future->getTimestamp() - $now->getTimestamp();
        }

        return $ttl;
    }

    public function __destruct()
    {
        $this->redis->close();
    }
}
