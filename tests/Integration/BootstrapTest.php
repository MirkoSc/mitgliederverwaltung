<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * docs/spec/01-plattform.md "Pflicht-Tests": the bootstrap sets the time
 * zone and zend.exception_ignore_args - whatever the host's php.ini says.
 * Runs the real app/src/bootstrap.php against a config file of its own.
 */
final class BootstrapTest extends TestCase
{
    private string $config;

    private string|false $vorherConfig;

    protected function setUp(): void
    {
        $this->config = (string) tempnam(sys_get_temp_dir(), 'mv-config-');
        $this->vorherConfig = getenv('APP_CONFIG_FILE');
    }

    protected function tearDown(): void
    {
        @unlink($this->config);
        putenv($this->vorherConfig === false ? 'APP_CONFIG_FILE' : 'APP_CONFIG_FILE=' . $this->vorherConfig);
        date_default_timezone_set('Europe/Berlin');
        ini_set('zend.exception_ignore_args', '1');
        ini_set('zend.exception_string_param_max_len', '0');
    }

    private function bootstrap(): Kernel
    {
        try {
            $kernel = require dirname(__DIR__, 2) . '/app/src/bootstrap.php';
        } finally {
            // The bootstrap installs the application's error handler; the
            // test runner keeps its own.
            restore_error_handler();
        }
        self::assertInstanceOf(Kernel::class, $kernel);

        return $kernel;
    }

    public function testItSetsTheTimeZoneAndHidesArgumentsInTraces(): void
    {
        file_put_contents($this->config, '<?php return ' . var_export(\App\Tests\Support\DatabaseTestCase::configData(), true) . ';');
        putenv('APP_CONFIG_FILE=' . $this->config);
        date_default_timezone_set('UTC');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');

        $this->bootstrap();

        self::assertSame('Europe/Berlin', date_default_timezone_get());
        self::assertSame('1', ini_get('zend.exception_ignore_args'));
        self::assertSame('0', ini_get('zend.exception_string_param_max_len'));
    }

    /** No config.php = not installed: every address leads to the installer (01 section 3). */
    public function testWithoutConfigEveryPageLeadsToTheInstaller(): void
    {
        putenv('APP_CONFIG_FILE=' . $this->config . '-fehlt');

        $antwort = $this->bootstrap()->handle(new Request(HttpMethod::Get, '/app/mitglieder'));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
        self::assertSame('/install', $antwort->headers['Location'] ?? null);
    }

    public function testWithConfigTheApplicationAnswers(): void
    {
        file_put_contents($this->config, '<?php return ' . var_export(\App\Tests\Support\DatabaseTestCase::configData(), true) . ';');
        putenv('APP_CONFIG_FILE=' . $this->config);

        $antwort = $this->bootstrap()->handle(new Request(HttpMethod::Get, '/'));

        self::assertInstanceOf(Response::class, $antwort);
        self::assertSame(302, $antwort->status);
    }
}
