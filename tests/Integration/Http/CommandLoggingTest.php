<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Http;

use Monolog\Level;

/**
 * What the container's output says about a command.
 *
 * The interface used to print the `commandId` under every write, along with a
 * link to its processing state. That is a developer's handle, not something the
 * person booking a fee has any use for, so it moved here - which makes these
 * records the only trace of a command outside `command_log`, and therefore
 * worth pinning down rather than trusting.
 *
 * The three cases that matter are the three outcomes: it worked, a rule said
 * no, or an idempotent retry replayed an earlier answer.
 */
final class CommandLoggingTest extends HttpTestCase
{
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->token(1, ['admin']);
        $this->givenParticipant(7, 'Anna');
    }

    public function testAnAcceptedCommandIsLoggedWithItsIdAndActor(): void
    {
        $response = $this->postTippYear();

        self::assertSame(202, $response->statusCode());

        $record = $this->recordMatching('Command accepted');

        self::assertSame(Level::Info, $record['level']);
        self::assertSame('AdminTippYearController::create', $record['context']['command']);
        self::assertSame($response->data()['commandId'], $record['context']['commandId']);
        self::assertSame('tester', $record['context']['actor']);
        self::assertSame(202, $record['context']['status']);
        self::assertSame($response->data()['resourceId'], $record['context']['resourceId']);
    }

    public function testARejectedCommandIsLoggedAsAWarningWithTheReason(): void
    {
        // B-10 refuses an overlapping tipp year. The interface shows the
        // message; the log is where the rule and the actor end up.
        $this->postTippYear();

        $rejected = $this->postTippYear([
            'name' => 'Tippjahr 2026 overlapping',
            'startDate' => '2026-06-01',
            'endDate' => '2027-05-31',
            'ticketCostPerRow' => 1.20,
        ]);

        self::assertSame(409, $rejected->statusCode());

        $record = $this->recordMatching('Command rejected');

        // Warning, not error: a business rule doing its job is not a fault.
        self::assertSame(Level::Warning, $record['level']);
        self::assertSame(409, $record['context']['status']);
        self::assertSame('tester', $record['context']['actor']);
        self::assertStringContainsString('overlaps', $record['context']['reason']);
        self::assertArrayHasKey('exception', $record['context']);
    }

    public function testAnIdempotentReplayIsLoggedRatherThanLookingLikeASecondWrite(): void
    {
        // Without this line the log would show one accepted command and then
        // nothing, and a retry storm would be invisible.
        $key = 'replay-me';

        $this->postTippYear(key: $key);
        $this->postTippYear(key: $key);

        $record = $this->recordMatching('Command replayed');

        self::assertSame(Level::Info, $record['level']);
        self::assertSame('AdminTippYearController::create', $record['context']['command']);
    }

    public function testTheLogNamesTheActorFromTheTokenRatherThanTheRequest(): void
    {
        // Whoever the token says, which is an assertion by Keycloak. A client
        // cannot write someone else's name into the log any more than it can
        // book a fee in their name.
        $this->send('POST', '/admin/tipp-years', $this->token(1, ['admin'], 'chef'), [
            'name' => 'Tippjahr 2027',
            'startDate' => '2027-01-01',
            'endDate' => '2027-12-31',
            'ticketCostPerRow' => 1.20,
        ]);

        self::assertSame('chef', $this->recordMatching('Command accepted')['context']['actor']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postTippYear(array $body = [], ?string $key = null): \BettingGame\Presentation\Http\JsonResponse
    {
        return $this->send(
            'POST',
            '/admin/tipp-years',
            $this->admin,
            $body === [] ? [
                'name' => 'Tippjahr 2026',
                'startDate' => '2026-01-01',
                'endDate' => '2026-12-31',
                'ticketCostPerRow' => 1.20,
            ] : $body,
            $key === null ? [] : ['Idempotency-Key' => $key]
        );
    }

    /**
     * @return array{level: Level, message: string, context: array<string, mixed>}
     */
    private function recordMatching(string $message): array
    {
        foreach ($this->logRecords->getRecords() as $record) {
            if (str_contains($record->message, $message)) {
                /** @var array<string, mixed> $context */
                $context = $record->context;

                return [
                    'level' => $record->level,
                    'message' => $record->message,
                    'context' => $context,
                ];
            }
        }

        self::fail(sprintf(
            'Nothing was logged matching "%s". Logged: %s',
            $message,
            implode(', ', array_map(
                static fn ($record): string => $record->message,
                $this->logRecords->getRecords()
            )) ?: '(nothing)'
        ));
    }
}
