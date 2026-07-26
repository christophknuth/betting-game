<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Presentation\Http\JsonResponse;
use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    public function testOkResponse(): void
    {
        $data = ['message' => 'Success'];
        $response = JsonResponse::ok($data);

        $this->assertEquals(200, $response->statusCode());
        $this->assertEquals($data, $response->data());
    }

    public function testAcceptedResponse(): void
    {
        $data = ['commandId' => 'cmd-123', 'status' => 'accepted'];
        $response = JsonResponse::accepted($data);

        $this->assertEquals(202, $response->statusCode());
        $this->assertEquals($data, $response->data());
    }

    public function testBadRequestResponse(): void
    {
        $response = JsonResponse::badRequest('Invalid input');

        $this->assertEquals(400, $response->statusCode());
        $data = $response->data();
        $this->assertEquals('Bad Request', $data['error']);
        $this->assertEquals('Invalid input', $data['message']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testUnauthorizedResponse(): void
    {
        $response = JsonResponse::unauthorized();

        $this->assertEquals(401, $response->statusCode());
        $data = $response->data();
        $this->assertEquals('Unauthorized', $data['error']);
    }

    public function testForbiddenResponse(): void
    {
        $response = JsonResponse::forbidden('Access denied');

        $this->assertEquals(403, $response->statusCode());
        $data = $response->data();
        $this->assertEquals('Forbidden', $data['error']);
        $this->assertEquals('Access denied', $data['message']);
    }

    public function testNotFoundResponse(): void
    {
        $response = JsonResponse::notFound('Resource not found');

        $this->assertEquals(404, $response->statusCode());
        $data = $response->data();
        $this->assertEquals('Not Found', $data['error']);
        $this->assertEquals('Resource not found', $data['message']);
    }

    public function testConflictResponse(): void
    {
        $response = JsonResponse::conflict('Duplicate entry');

        $this->assertEquals(409, $response->statusCode());
        $data = $response->data();
        $this->assertEquals('Conflict', $data['error']);
        $this->assertEquals('Duplicate entry', $data['message']);
    }

    public function testInternalErrorResponse(): void
    {
        $response = JsonResponse::internalError();

        $this->assertEquals(500, $response->statusCode());
        $data = $response->data();
        $this->assertEquals('Internal Server Error', $data['error']);
    }
}
