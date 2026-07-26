<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Psr\Log\LoggerInterface;

/**
 * Logger Factory for creating PSR-3 compliant loggers
 */
final class LoggerFactory
{
    /**
     * Create application logger with file handlers
     */
    public static function createApplicationLogger(string $environment = 'development'): LoggerInterface
    {
        $logger = new Logger('betting-game');

        // Log directory
        $logPath = __DIR__ . '/../../../var/log';
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        if ($environment === 'production') {
            // Production: Rotating file handler, only warnings and above
            $handler = new RotatingFileHandler(
                $logPath . '/app.log',
                30, // Keep 30 days
                Level::Warning
            );
        } else {
            // Development: Stream handler, all levels
            $handler = new StreamHandler(
                $logPath . '/app.log',
                Level::Debug
            );
        }

        // Custom formatter for better readability
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Create event store logger for event sourcing operations
     */
    public static function createEventStoreLogger(string $environment = 'development'): LoggerInterface
    {
        $logger = new Logger('event-store');

        $logPath = __DIR__ . '/../../../var/log';
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        if ($environment === 'production') {
            $handler = new RotatingFileHandler(
                $logPath . '/event-store.log',
                30,
                Level::Info
            );
        } else {
            $handler = new StreamHandler(
                $logPath . '/event-store.log',
                Level::Debug
            );
        }

        $formatter = new LineFormatter(
            "[%datetime%] %message% %context%\n",
            'Y-m-d H:i:s.u', // Microseconds for precise event timing
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Create error logger for critical errors
     */
    public static function createErrorLogger(): LoggerInterface
    {
        $logger = new Logger('errors');

        $logPath = __DIR__ . '/../../../var/log';
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        // Always log errors, regardless of environment
        $handler = new RotatingFileHandler(
            $logPath . '/error.log',
            90, // Keep 90 days for errors
            Level::Error
        );

        $formatter = new LineFormatter(
            "[%datetime%] %level_name%: %message%\n%context%\n%extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Create command/query logger for CQRS operations
     */
    public static function createCqrsLogger(string $environment = 'development'): LoggerInterface
    {
        $logger = new Logger('cqrs');

        $logPath = __DIR__ . '/../../../var/log';
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }

        if ($environment === 'production') {
            // In production, only log important operations
            $handler = new RotatingFileHandler(
                $logPath . '/cqrs.log',
                7,
                Level::Info
            );
        } else {
            // In development, log everything
            $handler = new StreamHandler(
                $logPath . '/cqrs.log',
                Level::Debug
            );
        }

        $formatter = new LineFormatter(
            "[%datetime%] %level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);

        return $logger;
    }
}
