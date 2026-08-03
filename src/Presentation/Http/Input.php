<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

/**
 * Typed access to decoded request bodies.
 *
 * A JSON body is `array<string, mixed>`; every read has to be narrowed before
 * it reaches a command. These helpers do that narrowing in one place and fail
 * loudly instead of silently casting - `(int) "abc"` would otherwise become 0.
 */
final class Input
{
    /**
     * A path segment as an integer.
     *
     * Route patterns already constrain most of these, but a controller must not
     * depend on the routing table's regex to keep its types honest.
     *
     * @param array<string, string> $params
     *
     * @throws InvalidInputException
     */
    public static function pathInt(array $params, string $key): int
    {
        $value = $params[$key] ?? null;

        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidInputException("$key in the path must be an integer");
        }

        return (int) $value;
    }

    /**
     * A JSON array of integers, e.g. the six numbers of a bet row.
     *
     * @param array<string, mixed> $data
     *
     * @return list<int>
     *
     * @throws InvalidInputException
     */
    public static function intList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            throw new InvalidInputException("$key must be an array of integers");
        }

        $numbers = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $numbers[] = $item;
                continue;
            }

            if (is_string($item) && preg_match('/^-?\d+$/', $item) === 1) {
                $numbers[] = (int) $item;
                continue;
            }

            throw new InvalidInputException("$key must contain integers only");
        }

        return $numbers;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidInputException("$key must be a non-empty string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function optionalString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::string($data, $key);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidInputException("$key must be an integer");
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function optionalInt(array $data, string $key): ?int
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::int($data, $key);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function float(array $data, string $key): float
    {
        $value = self::optionalFloat($data, $key);

        if ($value === null) {
            throw new InvalidInputException("$key must be a number");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function optionalFloat(array $data, string $key): ?float
    {
        if (!isset($data[$key])) {
            return null;
        }

        $value = $data[$key];

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new InvalidInputException("$key must be a number");
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function bool(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];

        if (is_bool($value)) {
            return $value;
        }

        throw new InvalidInputException("$key must be a boolean");
    }

    /**
     * A boolean the caller has to state.
     *
     * `bool()` above takes a default because most flags have a sensible one -
     * an unconfirmed distribution is refused, a filter nobody asked for is
     * off. Where the value *is* the instruction, a default would carry it out
     * on its own: "set the status" with no status in the body would deactivate
     * a participant.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputException
     */
    public static function requiredBool(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidInputException("$key is required and must be a boolean");
        }

        return self::bool($data, $key, false);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws InvalidInputException
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            throw new InvalidInputException("$key must be an object");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidInputException
     */
    public static function optionalArray(array $data, string $key): ?array
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::array($data, $key);
    }
}
