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
