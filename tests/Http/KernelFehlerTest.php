<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\Http\Zugriff;
use App\Support\FileLogger;
use App\View\View;
use PHPUnit\Framework\TestCase;

/**
 * The global error handling (docs/spec/01-plattform.md section 7): the log
 * gets class, message, method/route and place - never the full exception
 * string, never arguments, never request data; the page gets a reference
 * and no details. Taken over from Vereinsbelege (KernelErrorLogTest).
 *
 * The tests force zend.exception_ignore_args OFF on purpose: the guarantee
 * has to come from the handler, not only from the setting.
 */
final class KernelFehlerTest extends TestCase
{
    private const string GEHEIM = 'sup3r-secret-passw0rd';

    private string $errorLog;

    private string $appLog;

    private string|false $vorherIgnore;

    protected function setUp(): void
    {
        $this->vorherIgnore = ini_set('zend.exception_ignore_args', '0');
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'kernel_errlog_');
        $this->appLog = sys_get_temp_dir() . '/mv_kernel_' . uniqid('', true) . '/var/log/app.log';
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->vorherIgnore === false ? '1' : $this->vorherIgnore);
        @unlink($this->errorLog);
        @unlink($this->appLog);
        @rmdir(dirname($this->appLog));
        @rmdir(dirname($this->appLog, 2));
        @rmdir(dirname($this->appLog, 3));
    }

    /**
     * Inside the test method, not in setUp(): PHPUnit points error_log at a
     * capture file of its own right before the test method runs (and
     * restores it afterwards).
     */
    private function errorLogUmleiten(): void
    {
        ini_set('error_log', $this->errorLog);
    }

    private function kernel(Router $router, bool $debug = false): Kernel
    {
        $this->errorLogUmleiten();

        return new Kernel(
            $router,
            new StaticFileHandler(sys_get_temp_dir(), longCache: false),
            new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test'),
            debug: $debug,
            logger: new FileLogger($this->appLog),
        );
    }

    /**
     * A route whose handler throws with a secret travelling as a call
     * argument - exactly like a real controller -> service chain.
     */
    private static function routerMitGeheimnisImArgument(): Router
    {
        $router = new Router();
        $router->post('/anmelden', Zugriff::oeffentlich(), static function (Request $r, array $p): ResponseInterface {
            $versuch = static function (string $email, string $passwort): ResponseInterface {
                throw new \RuntimeException('database unavailable');
            };

            return $versuch('kasse@example.org', self::GEHEIM);
        });

        return $router;
    }

    private function antwort(Request $request, Router $router, bool $debug = false): Response
    {
        $antwort = $this->kernel($router, $debug)->handle($request);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    public function testNeitherLogContainsFunctionArgumentsLikePasswords(): void
    {
        $antwort = $this->antwort(new Request(HttpMethod::Post, '/anmelden', ip: '203.0.113.9'), self::routerMitGeheimnisImArgument());

        self::assertSame(500, $antwort->status);
        self::assertStringNotContainsString(self::GEHEIM, $antwort->body);

        foreach ([$this->errorLog, $this->appLog] as $log) {
            $inhalt = (string) file_get_contents($log);
            self::assertStringNotContainsString(self::GEHEIM, $inhalt, 'no plaintext password in ' . $log);
            self::assertStringContainsString('RuntimeException: database unavailable', $inhalt);
            self::assertStringContainsString('[POST /anmelden]', $inhalt);
        }
    }

    /** The page names a reference that leads to the log line - and nothing else. */
    public function testTheErrorPageShowsAReferenceAndNoDetails(): void
    {
        $antwort = $this->antwort(new Request(HttpMethod::Post, '/anmelden'), self::routerMitGeheimnisImArgument());

        self::assertSame(500, $antwort->status);
        self::assertSame(1, preg_match('/Referenz: <strong>([0-9A-F]{8})<\/strong>/', $antwort->body, $m));
        self::assertStringContainsString('[' . $m[1] . '] RuntimeException', (string) file_get_contents($this->appLog));
        self::assertStringNotContainsString('database unavailable', $antwort->body);
        self::assertStringNotContainsString('RuntimeException', $antwort->body);
        self::assertStringNotContainsString('.php', $antwort->body);
    }

    /** debug renders the exception string - which is why only dev has it on. */
    public function testDebugModeShowsTheExceptionForDevelopmentOnly(): void
    {
        $router = new Router();
        $router->get('/kaputt', Zugriff::oeffentlich(), static fn(): never => throw new \RuntimeException('interne Details'));

        self::assertStringNotContainsString('interne Details', $this->antwort(new Request(HttpMethod::Get, '/kaputt'), $router)->body);
        self::assertStringContainsString('interne Details', $this->antwort(new Request(HttpMethod::Get, '/kaputt'), $router, debug: true)->body);
    }

    public function testUnknownPathIsA404Page(): void
    {
        $antwort = $this->antwort(new Request(HttpMethod::Get, '/gibt-es-nicht'), new Router());

        self::assertSame(404, $antwort->status);
        self::assertStringContainsString('Seite nicht gefunden', $antwort->body);
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/nur-lesen', Zugriff::oeffentlich(), static fn(): Response => Response::html('ok'));

        $antwort = $this->antwort(new Request(HttpMethod::Post, '/nur-lesen'), $router);

        self::assertSame(405, $antwort->status);
        self::assertSame('GET', $antwort->headers['Allow'] ?? null);
    }
}
