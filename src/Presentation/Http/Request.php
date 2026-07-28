<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     */
    public function __construct(
        private string $method,
        private string $uri,
        private array $headers,
        private array $query,
        private ?string $body
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        /** @var array<string, mixed> $query */
        $query = $_GET;

        return new self(
            method: is_string($method) ? $method : 'GET',
            uri: is_string($uri) ? $uri : '/',
            headers: self::parseHeaders(),
            query: $query,
            body: file_get_contents('php://input') ?: null
        );
    }

    /** @return array<string, string> */
    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headerKey = str_replace('_', '-', substr($key, 5));
                $headers[$headerKey] = $value;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return parse_url($this->uri, PHP_URL_PATH) ?: '/';
    }

    public function header(string $name): ?string
    {
        $name = strtoupper(str_replace('-', '_', $name));
        return $this->headers[$name] ?? null;
    }

    public function queryParam(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A query parameter as an integer.
     *
     * @throws InvalidInputException when present but not a number - a filter
     *     the caller mistyped must not silently turn into 0 and match nothing
     */
    public function queryInt(string $name): ?int
    {
        $value = $this->queryParam($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidInputException("$name must be an integer");
        }

        return (int) $value;
    }

    /**
     * A query flag. Accepts the spellings a browser or client actually sends.
     */
    public function queryBool(string $name, bool $default = false): bool
    {
        $value = $this->queryParam($name);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Decoded JSON request body. Values are mixed by nature - callers must
     * narrow them before use.
     *
     * @return array<string, mixed>
     */
    public function jsonBody(): array
    {
        if ($this->body === null) {
            return [];
        }

        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
