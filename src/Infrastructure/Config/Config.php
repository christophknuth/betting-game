<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Config;

/**
 * Typed access to the configuration array.
 *
 * config.php returns `array<string, mixed>` with nested sections, so every read
 * is mixed. Values are addressed by dot path (`db.host`) and narrowed here, with
 * a default for anything missing.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    public function nullableString(string $path): ?string
    {
        $value = $this->get($path);

        return is_string($value) ? $value : null;
    }

    public function int(string $path, int $default = 0): int
    {
        $value = $this->get($path);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    public function bool(string $path, bool $default = false): bool
    {
        $value = $this->get($path);

        return is_bool($value) ? $value : $default;
    }

    private function get(string $path): mixed
    {
        $current = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
