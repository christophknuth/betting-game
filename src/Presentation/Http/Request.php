<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

final class Request
{
    private array $attributes = [];

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
        return new self(
            method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
            uri: $_SERVER['REQUEST_URI'] ?? '/',
            headers: self::parseHeaders(),
            query: $_GET,
            body: file_get_contents('php://input') ?: null
        );
    }

    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
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
        return $this->query[$name] ?? null;
    }

    public function jsonBody(): array
    {
        if ($this->body === null) {
            return [];
        }

        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
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
