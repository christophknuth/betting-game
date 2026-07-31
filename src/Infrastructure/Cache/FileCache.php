<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Cache;

use Psr\SimpleCache\CacheInterface;
use DateInterval;

/**
 * PSR-16 compliant file-based cache implementation
 * Suitable for development and small-scale deployments
 */
final class FileCache implements CacheInterface
{
    private string $cacheDir;
    private int $defaultTtl;

    // `?string` explicitly: PHP 8.4 deprecates inferring the nullability from a
    // `= null` default.
    public function __construct(?string $cacheDir = null, int $defaultTtl = 3600)
    {
        $this->cacheDir = $cacheDir ?? __DIR__ . '/../../../var/cache';
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Fetches a value from the cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        $filename = $this->getFilename($key);

        if (!file_exists($filename)) {
            return $default;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            return $default;
        }

        $data = $this->decodeEntry($content);

        if ($data === null) {
            unlink($filename);
            return $default;
        }

        // Check expiration
        if ($data['expires_at'] !== null && $data['expires_at'] < time()) {
            unlink($filename);
            return $default;
        }

        return $data['value'];
    }

    /**
     * Reads a cache file back into its entry shape.
     *
     * A corrupted or foreign file must not take the caller down, so anything
     * that does not match the expected shape is treated as a cache miss.
     *
     * @return array{expires_at: int|null, value: mixed}|null
     */
    private function decodeEntry(string $content): ?array
    {
        $data = @unserialize($content);

        if (!is_array($data) || !array_key_exists('value', $data) || !array_key_exists('expires_at', $data)) {
            return null;
        }

        $expiresAt = $data['expires_at'];

        if ($expiresAt !== null && !is_int($expiresAt)) {
            return null;
        }

        return ['expires_at' => $expiresAt, 'value' => $data['value']];
    }

    /**
     * Persists data in the cache.
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);

        $ttlSeconds = $this->normalizeTtl($ttl);
        // A TTL of 0 or less means "no expiry" - store the entry without one.
        $expiresAt = $ttlSeconds <= 0 ? null : time() + $ttlSeconds;

        $data = [
            'value' => $value,
            'expires_at' => $expiresAt,
            'created_at' => time(),
        ];

        $filename = $this->getFilename($key);
        return file_put_contents($filename, serialize($data), LOCK_EX) !== false;
    }

    /**
     * Delete an item from the cache by its unique key.
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        $filename = $this->getFilename($key);

        if (!file_exists($filename)) {
            return true;
        }

        return unlink($filename);
    }

    /**
     * Wipes clean the entire cache's keys.
     */
    public function clear(): bool
    {
        $files = glob($this->cacheDir . '/*.cache');

        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }

    /**
     * Obtains multiple cache items by their unique keys.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * Persists a set of key => value pairs in the cache.
     */
    /** @param iterable<string, mixed> $values */
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

    /**
     * Deletes multiple cache items in a single operation.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Determines whether an item is present in the cache.
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);

        $filename = $this->getFilename($key);

        if (!file_exists($filename)) {
            return false;
        }

        $content = file_get_contents($filename);
        if ($content === false) {
            return false;
        }

        $data = $this->decodeEntry($content);

        if ($data === null) {
            unlink($filename);
            return false;
        }

        // Check expiration
        if ($data['expires_at'] !== null && $data['expires_at'] < time()) {
            unlink($filename);
            return false;
        }

        return true;
    }

    /**
     * Get cache filename for key
     */
    private function getFilename(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }

    /**
     * Validate cache key
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new CacheInvalidArgumentException('Cache key cannot be empty');
        }

        if (preg_match('/[{}()\/@:]/', $key)) {
            throw new CacheInvalidArgumentException('Cache key contains reserved characters');
        }
    }

    /**
     * Normalize TTL to seconds
     */
    private function normalizeTtl(null|int|DateInterval $ttl): int
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
}
