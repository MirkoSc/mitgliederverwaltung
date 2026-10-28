<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Konto;
use App\Domain\Rolle;
use App\Http\CsrfSchutz;
use App\Http\HttpMethod;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Router;
use App\Http\Session;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestKonten;
use PHPUnit\Framework\TestCase;

/**
 * The route extension point (docs/spec/05-entwicklung.md section 6): an
 * issue adds `app/routes/<bereich>.php`, and its routes exist - with the
 * guard and the CSRF check its declarations ask for - without a change to
 * any central file.
 */
final class RoutenDateienTest extends TestCase
{
    private TestKonten $konten;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->konten = new TestKonten();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function routerAus(string $verzeichnis): Router
    {
        $dienste = AppTestCase::baueDienste($this->konten);
        $router = new Router();
        new Routen(
            $router,
            $dienste,
            new LoginGuard($dienste->session, $dienste->view, $this->konten),
            new CsrfSchutz($dienste->session, $dienste->view),
        )->ladeDateien($verzeichnis);

        return $router;
    }

    private function aufrufen(Router $router, Request $request): Response
    {
        $treffer = $router->match($request->method, $request->path);
        self::assertNotNull($treffer->handler);
        $antwort = ($treffer->handler)($request, $treffer->params);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    public function testANewRouteFileIsLoadedWithItsDeclarations(): void
    {
        $router = $this->routerAus(dirname(__DIR__) . '/Fixtures/routen');

        self::assertSame(
            ['GET /app/beispiel -> mitglieder.sehen', 'POST /app/beispiel -> mitglieder.bearbeiten'],
            array_map(static fn($r): string => $r->method->value . ' ' . $r->pattern . ' -> ' . $r->zugriff->beschreibung(), $router->routes()),
        );
    }

    public function testItsRoutesAreGuardedAndCsrfProtectedWithoutAskingForIt(): void
    {
        $router = $this->routerAus(dirname(__DIR__) . '/Fixtures/routen');

        // Anonymous: the login.
        self::assertSame(302, $this->aufrufen($router, new Request(HttpMethod::Get, '/app/beispiel'))->status);

        $this->konten->hinzufuegen(new Konto(3, 'V', Rolle::Vorstand));
        $session = new Session();
        $session->start();
        $session->login(3);

        self::assertSame('Beispielseite', $this->aufrufen($router, new Request(HttpMethod::Get, '/app/beispiel'))->body);
        self::assertSame(403, $this->aufrufen($router, new Request(HttpMethod::Post, '/app/beispiel'))->status, 'no token');
        self::assertSame('gespeichert', $this->aufrufen($router, new Request(HttpMethod::Post, '/app/beispiel', post: ['_csrf' => $session->csrfToken()]))->body);
    }

    /** 01 section 5: the cron's token is checked centrally, like the login. */
    public function testACronRouteNeedsTheTokenFromTheConfig(): void
    {
        $router = $this->routerAus(dirname(__DIR__) . '/Fixtures/routen-cron');
        $aufruf = fn(array $query): Response => $this->aufrufen($router, new Request(HttpMethod::Get, '/cron', query: $query));

        self::assertSame(403, $aufruf([])->status);
        self::assertSame(403, $aufruf(['token' => 'falsch'])->status);
        self::assertSame(403, $aufruf(['token' => ['test-token']])->status);
        self::assertSame('cron gelaufen', $aufruf(['token' => 'test-token'])->body);
    }

    public function testARouteFileMustReturnItsClosure(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('kaputt.php must return a Closure');

        $this->routerAus(dirname(__DIR__) . '/Fixtures/routen-kaputt');
    }

    /** Every file of the application follows the convention. */
    public function testEveryRouteFileOfTheApplicationHasAReviewedAccessList(): void
    {
        $wurzel = dirname(__DIR__, 2);
        foreach (glob($wurzel . '/app/routes/*.php') ?: [] as $datei) {
            self::assertFileExists(
                __DIR__ . '/routen-zugriff/' . basename($datei, '.php') . '.txt',
                'tests/Http/routen-zugriff/ needs a file for ' . basename($datei),
            );
        }
    }
}
