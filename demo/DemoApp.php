<?php

declare(strict_types=1);

namespace BettingGame\Demo;

use BettingGame\Application\Query\GetAllPredictionsHandler;
use BettingGame\Application\Query\GetAllPredictionsQuery;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Application\Query\GetParticipantPredictionsQuery;
use BettingGame\Application\Query\GetPredictionHandler;
use BettingGame\Application\Query\GetPredictionQuery;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\ResultRepositoryInterface;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Read-only demo of the prediction and result slice.
 *
 * Deliberately does not reuse Presentation\Router, because that router also
 * exposes the write side. Everything below the routing - handlers, repositories,
 * read models - is the real production code.
 */
final class DemoApp
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(string $method, string $path, array $query): array
    {
        if ($method !== 'GET') {
            return [405, ['error' => 'This demo is read-only', 'allowed' => ['GET']]];
        }

        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        if ($path === '/') {
            return [200, $this->index()];
        }

        if ($path === '/demo-data') {
            return [200, $this->demoData()];
        }

        if ($path === '/predictions') {
            return [200, $this->allPredictions($query)];
        }

        if (preg_match('#^/participants/(\d+)/predictions$#', $path, $m) === 1) {
            return [200, $this->participantPredictions((int) $m[1], $query)];
        }

        if (preg_match('#^/participants/(\d+)/predictions/([^/]+)$#', $path, $m) === 1) {
            return $this->singlePrediction($m[2], (int) $m[1]);
        }

        if (preg_match('#^/events/(\d+)/result$#', $path, $m) === 1) {
            return $this->result((int) $m[1]);
        }

        return [404, [
            'error' => 'Not Found',
            'message' => "No demo endpoint for $path",
            'hint' => 'GET / lists them',
        ]];
    }

    /** @return array<string, mixed> */
    private function index(): array
    {
        return [
            'name' => 'Betting Game - read-only demo',
            'note' => 'Predictions and results, queries only. No authentication, no commands.',
            'endpoints' => [
                'GET /predictions'
                    => 'All predictions (filters: participantId, eventId, bettingGameId, page, pageSize)',
                'GET /participants/{participantId}/predictions'
                    => 'Predictions of one participant (filters: eventId, status)',
                'GET /participants/{participantId}/predictions/{predictionId}'
                    => 'A single prediction',
                'GET /events/{eventId}/result'
                    => 'The recorded result of an event',
                'GET /demo-data'
                    => 'What is in the seeded database',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function demoData(): array
    {
        $pdo = $this->pdo();
        $counts = [];

        foreach (['participant', 'betting_game', 'event', 'prediction', 'result', 'participant_score'] as $table) {
            $counts[$table] = (int) $this->scalar($pdo, "SELECT COUNT(*) FROM $table");
        }

        return [
            'rowCounts' => $counts,
            'participants' => $this->rows($pdo, 'SELECT participant_id, display_name FROM participant ORDER BY 1'),
            'events' => $this->rows($pdo, 'SELECT event_id, event_name, deadline FROM event ORDER BY 1'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function allPredictions(array $query): array
    {
        $handler = $this->service(GetAllPredictionsHandler::class);

        return $handler->handle(new GetAllPredictionsQuery(
            bettingGameId: self::intOrNull($query['bettingGameId'] ?? null),
            eventId: self::intOrNull($query['eventId'] ?? null),
            participantId: self::intOrNull($query['participantId'] ?? null),
            page: self::intOrNull($query['page'] ?? null) ?? 1,
            pageSize: self::intOrNull($query['pageSize'] ?? null) ?? 50
        ))->data();
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function participantPredictions(int $participantId, array $query): array
    {
        $handler = $this->service(GetParticipantPredictionsHandler::class);
        $status = $query['status'] ?? null;

        return $handler->handle(new GetParticipantPredictionsQuery(
            participantId: $participantId,
            bettingGameId: self::intOrNull($query['bettingGameId'] ?? null),
            eventId: self::intOrNull($query['eventId'] ?? null),
            status: is_string($status) ? $status : null
        ))->data();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function singlePrediction(string $predictionId, int $participantId): array
    {
        try {
            $result = $this->service(GetPredictionHandler::class)
                ->handle(new GetPredictionQuery($predictionId, $participantId));
        } catch (EntityNotFoundException $e) {
            return [404, ['error' => 'Not Found', 'message' => $e->getMessage()]];
        }

        return [200, $result->data()];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function result(int $eventId): array
    {
        $result = $this->service(ResultRepositoryInterface::class)->findByEventId($eventId);

        if ($result === null) {
            return [404, ['error' => 'Not Found', 'message' => 'No result recorded for this event']];
        }

        return [200, [
            'resultId' => $result->id(),
            'eventId' => $result->eventId(),
            'resultData' => $result->resultData(),
            'source' => $result->source(),
            'recordedAt' => $result->recordedAt()->format('c'),
            'updatedAt' => $result->updatedAt()?->format('c'),
        ]];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        /** @var T $service */
        $service = $this->container->get($class);

        return $service;
    }

    private function pdo(): PDO
    {
        return $this->service(PDO::class);
    }

    private function scalar(PDO $pdo, string $sql): string
    {
        $stmt = $pdo->query($sql);
        $value = $stmt === false ? false : $stmt->fetchColumn();

        return is_scalar($value) ? (string) $value : '0';
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);

        if ($stmt === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }
}
