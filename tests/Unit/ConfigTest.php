<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards config.php itself.
 *
 * It used to read $_ENV directly, which the official PHP images never populate
 * - so every setting silently fell back to its default and the application
 * could not be configured. Worse, the undefined-key warning was *output*, and
 * output before the response means the headers are already sent: every status
 * code became 200, including 401 and 409.
 *
 * Both failures are invisible in a unit test of any other class, which is why
 * the config file gets its own.
 */
final class ConfigTest extends TestCase
{
    /** @var list<string> */
    private const MANAGED = [
        'APP_ENV', 'APP_DEBUG', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'DB_USERNAME', 'DB_PASSWORD', 'CACHE_DRIVER', 'CACHE_TTL',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    /**
     * Clears the variables under test, remembering what they were.
     *
     * Restoring matters: these tests share a process with the integration
     * suite, and simply unsetting DB_HOST here left every later test unable to
     * find the database.
     */
    protected function setUp(): void
    {
        foreach (self::MANAGED as $key) {
            $this->saved[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                putenv($key);
                continue;
            }

            putenv("$key=$value");
        }

        $this->saved = [];
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $config = require __DIR__ . '/../../config/config.php';

        self::assertIsArray($config);

        /** @var array<string, mixed> $config */
        return $config;
    }

    public function testLoadingEmitsNoOutput(): void
    {
        ob_start();
        $this->load();
        $output = ob_get_clean();

        self::assertSame(
            '',
            $output,
            'any output here would send the headers and pin every response to 200'
        );
    }

    public function testEnvironmentVariablesAreActuallyRead(): void
    {
        putenv('DB_HOST=db.example.test');
        putenv('DB_PORT=3307');
        putenv('DB_DATABASE=lotto');

        $config = $this->load();

        self::assertIsArray($config['db']);
        self::assertSame('db.example.test', $config['db']['host']);
        self::assertSame(3307, $config['db']['port'], 'the port has to be an int for the DSN');
        self::assertSame('lotto', $config['db']['database']);
    }

    public function testDefaultsApplyWhenNothingIsSet(): void
    {
        $config = $this->load();

        self::assertIsArray($config['db']);
        self::assertSame('localhost', $config['db']['host']);
        self::assertSame(3306, $config['db']['port']);
        self::assertSame('development', $config['environment']);
    }

    public function testProductionTurnsDebugOff(): void
    {
        putenv('APP_ENV=production');

        $config = $this->load();

        self::assertTrue($config['production']);
        self::assertFalse($config['debug'], 'a production 500 must not carry an exception message');
    }

    public function testDebugCanBeForcedOnInProduction(): void
    {
        putenv('APP_ENV=production');
        putenv('APP_DEBUG=true');

        $config = $this->load();

        self::assertTrue($config['debug']);
    }

    public function testDevelopmentDefaultsToDebug(): void
    {
        $config = $this->load();

        self::assertFalse($config['production']);
        self::assertTrue($config['debug']);
    }

    public function testAnEmptyVariableFallsBackToTheDefault(): void
    {
        putenv('DB_HOST=');

        $config = $this->load();

        self::assertIsArray($config['db']);
        self::assertSame('localhost', $config['db']['host']);
    }
}
