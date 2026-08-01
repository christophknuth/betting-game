<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * PSR-3 loggers, all of them writing to the container's output.
 *
 * They used to write into `var/log/*.log` with a rotating handler. In a
 * container that is the wrong end: the files sit inside a filesystem nobody
 * looks at, `docker-compose logs` shows nothing, and rotation duplicates work
 * the runtime already does. So everything goes to stdout - warnings and worse
 * to stderr - and whatever collects the container's output decides what
 * happens next.
 *
 * The format follows suit: JSON in production, because something is going to
 * parse it, and a readable line in development, because a person is.
 */
final class LoggerFactory
{
    /** General application logging. */
    public static function createApplicationLogger(string $environment = 'development'): LoggerInterface
    {
        return self::streamLogger('betting-game', $environment);
    }

    /** Event sourcing operations. */
    public static function createEventStoreLogger(string $environment = 'development'): LoggerInterface
    {
        return self::streamLogger('event-store', $environment);
    }

    /** Errors only, always, whatever the environment. */
    public static function createErrorLogger(): LoggerInterface
    {
        $logger = new Logger('errors');
        $logger->pushHandler(self::handler('php://stderr', Level::Error, 'production'));

        return $logger;
    }

    /** Command and query processing (OPS-01). */
    public static function createCqrsLogger(string $environment = 'development'): LoggerInterface
    {
        return self::streamLogger('cqrs', $environment);
    }

    /**
     * Two handlers rather than one: anything from warning upwards belongs on
     * stderr, so a runtime that separates the two streams - and most do - keeps
     * complaints apart from routine chatter without having to parse anything.
     *
     * The push order is the part that is easy to get backwards. Monolog calls
     * handlers in reverse: the one pushed *last* runs *first*. So stderr goes
     * on last, and it stops the record from bubbling - otherwise a warning
     * would be written twice, and pushing them the other way round would send
     * warnings to stdout and never reach stderr at all.
     */
    private static function streamLogger(string $channel, string $environment): LoggerInterface
    {
        $logger = new Logger($channel);

        $floor = $environment === 'production' ? Level::Info : Level::Debug;

        $stderr = self::handler('php://stderr', Level::Warning, $environment);
        $stderr->setBubble(false);

        $logger->pushHandler(self::handler('php://stdout', $floor, $environment));
        $logger->pushHandler($stderr);

        return $logger;
    }

    private static function handler(string $stream, Level $level, string $environment): StreamHandler
    {
        $handler = new StreamHandler($stream, $level);

        $handler->setFormatter(
            $environment === 'production'
                ? new JsonFormatter()
                : new LineFormatter(
                    "[%datetime%] %channel%.%level_name%: %message% %context%\n",
                    'Y-m-d H:i:s',
                    true,
                    true
                )
        );

        return $handler;
    }
}
