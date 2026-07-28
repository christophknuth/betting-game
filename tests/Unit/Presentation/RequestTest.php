<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Presentation\Http\InvalidInputException;
use BettingGame\Presentation\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(array $headers = [], string $uri = '/', ?string $body = null): Request
    {
        return new Request('GET', $uri, $headers, [], $body);
    }

    /**
     * The regression this exists for: parseHeaders() and header() used to
     * disagree about hyphens, so any header with more than one word was
     * invisible. Authorization survived because it has only one.
     */
    public function testAHyphenatedHeaderIsFound(): void
    {
        $request = $this->request(['IDEMPOTENCY_KEY' => 'abc-123']);

        self::assertSame('abc-123', $request->header('Idempotency-Key'));
        self::assertSame('abc-123', $request->header('idempotency-key'));
        self::assertSame('abc-123', $request->header('IDEMPOTENCY_KEY'));
    }

    public function testFromGlobalsUsesTheSameSpellingAsHeader(): void
    {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'from-globals';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer t';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';

        try {
            $request = Request::fromGlobals();

            self::assertSame('from-globals', $request->header('Idempotency-Key'));
            self::assertSame('Bearer t', $request->header('Authorization'));
        } finally {
            unset(
                $_SERVER['HTTP_IDEMPOTENCY_KEY'],
                $_SERVER['HTTP_AUTHORIZATION'],
                $_SERVER['REQUEST_METHOD'],
                $_SERVER['REQUEST_URI']
            );
        }
    }

    public function testAMissingHeaderIsNull(): void
    {
        self::assertNull($this->request()->header('Idempotency-Key'));
    }

    public function testTheQueryStringIsStrippedFromTheUri(): void
    {
        self::assertSame('/participants/7/fees', $this->request([], '/participants/7/fees?a=1')->uri());
    }

    public function testAnEmptyBodyDecodesToAnEmptyArray(): void
    {
        self::assertSame([], $this->request()->jsonBody());
        self::assertSame([], $this->request([], '/', 'not json')->jsonBody());
    }

    public function testQueryIntRejectsNonNumbers(): void
    {
        $request = new Request('GET', '/', [], ['tippYearId' => 'abc'], null);

        $this->expectException(InvalidInputException::class);
        $request->queryInt('tippYearId');
    }

    public function testQueryIntAcceptsNumbersAndAbsence(): void
    {
        $request = new Request('GET', '/', [], ['a' => '42', 'b' => ''], null);

        self::assertSame(42, $request->queryInt('a'));
        self::assertNull($request->queryInt('b'));
        self::assertNull($request->queryInt('missing'));
    }

    public function testQueryBoolAcceptsTheUsualSpellings(): void
    {
        $request = new Request(
            'GET',
            '/',
            [],
            ['t' => 'true', 'one' => '1', 'yes' => 'yes', 'f' => 'false', 'empty' => ''],
            null
        );

        self::assertTrue($request->queryBool('t'));
        self::assertTrue($request->queryBool('one'));
        self::assertTrue($request->queryBool('yes'));
        self::assertFalse($request->queryBool('f'));
        self::assertFalse($request->queryBool('empty'));
        self::assertTrue($request->queryBool('missing', true), 'absent falls back to the default');
    }
}
