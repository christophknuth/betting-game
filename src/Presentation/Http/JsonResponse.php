<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

final class JsonResponse
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function __construct(
        private int $statusCode,
        private array $data,
        private array $headers = []
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data): self
    {
        return new self(200, $data);
    }

    /**
     * An arbitrary status with a body - used to replay a stored response for an
     * idempotent retry, which has to come back with its original status.
     *
     * @param array<string, mixed> $data
     */
    public static function of(int $statusCode, array $data): self
    {
        return new self($statusCode, $data);
    }

    /** @param array<string, mixed> $data */
    public function withData(array $data): self
    {
        return new self($this->statusCode, [...$this->data, ...$data], $this->headers);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->statusCode, $this->data, [...$this->headers, $name => $value]);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param array<string, mixed> $data */
    public static function accepted(array $data): self
    {
        return new self(202, $data);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, [
            'error' => 'Bad Request',
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(401, [
            'error' => 'Unauthorized',
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, [
            'error' => 'Forbidden',
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(404, [
            'error' => 'Not Found',
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * 405, with the Allow header RFC 9110 requires on this status.
     *
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(
            405,
            [
                'error' => 'Method Not Allowed',
                'message' => 'Allowed: ' . implode(', ', $allowedMethods),
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ],
            ['Allow' => implode(', ', $allowedMethods)]
        );
    }

    public static function conflict(string $message): self
    {
        return new self(409, [
            'error' => 'Conflict',
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    public static function internalError(string $message = 'Internal Server Error'): self
    {
        return new self(500, [
            'error' => 'Internal Server Error',
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * 503, for a dependency we need and cannot reach.
     *
     * Separate from 401 on purpose: a client that gets 401 discards its token
     * and re-authenticates, which is precisely the wrong move when the reason
     * is that we cannot reach the identity provider.
     */
    public static function serviceUnavailable(string $message = 'Service Unavailable'): self
    {
        return new self(503, [
            'error' => 'Service Unavailable',
            'message' => $message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        header('Content-Type: application/json');
        foreach ($this->headers as $key => $value) {
            header("$key: $value");
        }

        echo json_encode($this->data, JSON_THROW_ON_ERROR);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }
}
