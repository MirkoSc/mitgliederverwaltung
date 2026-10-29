<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Anwendung;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Domain\Rolle;
use App\Http\CsrfSchutz;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Route;
use App\Http\Router;
use App\Http\Session;
use App\Http\Zugriff;
use App\Http\ZugriffArt;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TestKonten;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The access matrix over every route (docs/spec/02-sicherheit.md section 6
 * and "Pflicht-Tests", 01 "Routen-Zugriffsmatrix").
 *
 * Built from the real route files under app/routes/ with the real login
 * guard and CSRF check (App\Anwendung) - only the handlers are replaced by
 * a stand-in that says "reached". Every route is called as anonymous
 * visitor and as each of the two roles.
 *
 * Every declaration is listed in tests/Http/routen-zugriff/, one file per
 * route file: a new route, a removed one or a changed declaration fails
 * here until the list changes in the same pull request - visibly, in the
 * diff (docs/spec/05-entwicklung.md section 6).
 */
final class RoutenZugriffTest extends TestCase
{
    public const string ERREICHT = 'ERREICHT';

    /**
     * What may be public at all (docs/ENTSCHEIDUNGEN.md E-F3): the login and
     * everything below it (password reset, invitation), logging out, the
     * legal pages, and "/" which only redirects.
     */
    private const array OEFFENTLICH_GENAU = ['/', '/abmelden', '/impressum', '/datenschutz', '/logo/{groesse}'];

    private const string OEFFENTLICH_BEREICH = '/anmelden';

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

    private static function routerMit(TestKonten $konten): Router
    {
        $stellvertreter = static fn(HttpMethod $methode, string $muster, Zugriff $zugriff, \Closure $handler): \Closure
            => static fn(): Response => Response::html(self::ERREICHT);

        return Anwendung::kernel(AppTestCase::baueDienste($konten), $stellvertreter)->router();
    }

    private function router(): Router
    {
        return self::routerMit($this->konten);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routen(): iterable
    {
        foreach (self::routerMit(new TestKonten())->routes() as $route) {
            yield $route->method->value . ' ' . $route->pattern => [$route->method->value, $route->pattern];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function schreibRouten(): iterable
    {
        foreach (self::routerMit(new TestKonten())->routes() as $route) {
            if (CsrfSchutz::noetig($route->method, $route->zugriff)) {
                yield $route->method->value . ' ' . $route->pattern => [$route->method->value, $route->pattern];
            }
        }
    }

    #[DataProvider('routen')]
    public function testEveryRoleGetsExactlyWhatTheDeclarationAllows(string $methode, string $muster): void
    {
        $router = $this->router();
        $route = self::finde($router, $methode, $muster);
        $pfad = self::beispielPfad($muster);

        $_SESSION = [];
        $antwort = $this->aufrufen($router, $route->method, $pfad);
        if ($route->zugriff->art === ZugriffArt::Cron) {
            self::assertSame(403, $antwort->status, $muster . ' must want the cron token');
        } elseif ($route->zugriff->brauchtAnmeldung()) {
            self::assertTrue(
                $antwort->status === 401 || ($antwort->status === 302 && str_starts_with($antwort->headers['Location'] ?? '', '/anmelden')),
                $muster . ' must turn an anonymous visitor away',
            );
        } else {
            self::assertSame(self::ERREICHT, $antwort->body, $muster . ' is public and must be reachable');
        }

        foreach (Rolle::cases() as $rolle) {
            $this->anmelden($rolle);
            $antwort = $this->aufrufen($router, $route->method, $pfad);
            $erwartet = self::erlaubt($route->zugriff, $rolle);

            self::assertSame(
                $erwartet,
                $antwort->body === self::ERREICHT,
                sprintf('%s %s as %s (declared: %s, answer %d)', $methode, $muster, $rolle->value, $route->zugriff->beschreibung(), $antwort->status),
            );
            if (!$erwartet) {
                self::assertSame(403, $antwort->status, $muster . ' must answer 403 to ' . $rolle->value);
            }
        }
    }

    #[DataProvider('schreibRouten')]
    public function testEveryWritingRouteWantsACsrfToken(string $methode, string $muster): void
    {
        $router = $this->router();
        $route = self::finde($router, $methode, $muster);
        $this->anmelden(Rolle::Admin);

        $antwort = $this->aufrufen($router, $route->method, self::beispielPfad($muster), mitToken: false);

        self::assertNotSame(self::ERREICHT, $antwort->body, $muster . ' ran without a CSRF token');
        self::assertSame(403, $antwort->status);
    }

    public function testEveryRouteDeclarationIsReviewed(): void
    {
        $erwartet = [];
        foreach (glob(__DIR__ . '/routen-zugriff/*.txt') ?: [] as $datei) {
            foreach (file($datei, FILE_IGNORE_NEW_LINES) ?: [] as $zeile) {
                if (trim($zeile) !== '' && !str_starts_with($zeile, '#')) {
                    $erwartet[] = $zeile;
                }
            }
        }

        $tatsaechlich = array_map(
            static fn(Route $route): string => $route->method->value . ' ' . $route->pattern . ' -> ' . $route->zugriff->beschreibung(),
            $this->router()->routes(),
        );

        $neu = array_values(array_diff($tatsaechlich, $erwartet));
        $weg = array_values(array_diff($erwartet, $tatsaechlich));
        self::assertSame(
            [[], []],
            [$neu, $weg],
            "Route declarations differ from tests/Http/routen-zugriff/*.txt.\n"
            . "Not yet reviewed (add to the file of your route file after checking against 02-sicherheit.md section 6):\n  "
            . implode("\n  ", $neu) . "\n"
            . "Listed but no longer served:\n  " . implode("\n  ", $weg),
        );
        self::assertCount(count(array_unique($tatsaechlich)), $tatsaechlich, 'a route is registered twice');
        self::assertCount(count(array_unique($erwartet)), $erwartet, 'a declaration is listed twice');
    }

    public function testOnlyTheWayInAndTheLegalPagesArePublic(): void
    {
        foreach ($this->router()->routes() as $route) {
            if ($route->zugriff->art !== ZugriffArt::Oeffentlich) {
                continue;
            }
            self::assertTrue(
                in_array($route->pattern, self::OEFFENTLICH_GENAU, true)
                    || $route->pattern === self::OEFFENTLICH_BEREICH
                    || str_starts_with($route->pattern, self::OEFFENTLICH_BEREICH . '/'),
                $route->pattern . ' is public, but only the login, the legal pages and "/" may be (E-F3)',
            );
        }
    }

    /** The cron token opens /cron and nothing else (01 section 5). */
    public function testOnlyTheCronIsDeclaredCron(): void
    {
        $ausserhalb = [];
        foreach ($this->router()->routes() as $route) {
            if ($route->zugriff->art === ZugriffArt::Cron && $route->pattern !== '/cron' && !str_starts_with($route->pattern, '/cron/')) {
                $ausserhalb[] = $route->pattern;
            }
        }

        self::assertSame([], $ausserhalb, 'declared cron outside /cron');
    }

    /** /admin is the admin's: every route there declares an admin.* right. */
    public function testEveryAdminRouteNeedsAnAdminRight(): void
    {
        foreach ($this->router()->routes() as $route) {
            if ($route->pattern !== '/admin' && !str_starts_with($route->pattern, '/admin/')) {
                continue;
            }
            self::assertTrue(
                $route->zugriff->art === ZugriffArt::Recht && $route->zugriff->recht?->istAdmin() === true,
                $route->pattern . ' is declared ' . $route->zugriff->beschreibung(),
            );
        }
    }

    public function testEveryAppRouteNeedsALogin(): void
    {
        foreach ($this->router()->routes() as $route) {
            if ($route->pattern === '/app' || str_starts_with($route->pattern, '/app/')) {
                self::assertTrue($route->zugriff->brauchtAnmeldung(), $route->pattern);
            }
        }
    }

    /**
     * Spot checks against the spec itself (02 section 6, 06 "Vorstand sieht
     * keine Beiträge"), so the matrix above cannot pass merely because
     * declaration and expectation share a bug.
     */
    public function testSpotChecksAgainstTheSpec(): void
    {
        $router = $this->router();
        $faelle = [
            [Rolle::Admin, HttpMethod::Get, '/app/mitglieder', true],
            [Rolle::Vorstand, HttpMethod::Get, '/app/mitglieder', true],
            [Rolle::Admin, HttpMethod::Get, '/app/listen', true],
            [Rolle::Vorstand, HttpMethod::Get, '/app/listen', true],
            [Rolle::Admin, HttpMethod::Get, '/app/beitraege', true],
            [Rolle::Vorstand, HttpMethod::Get, '/app/beitraege', false],
            [Rolle::Admin, HttpMethod::Get, '/admin', true],
            [Rolle::Vorstand, HttpMethod::Get, '/admin', false],
        ];

        foreach ($faelle as [$rolle, $methode, $pfad, $erwartet]) {
            $this->anmelden($rolle);
            $antwort = $this->aufrufen($router, $methode, $pfad);

            self::assertSame($erwartet, $antwort->body === self::ERREICHT, $rolle->value . ' ' . $pfad);
        }
    }

    private static function erlaubt(Zugriff $zugriff, Rolle $rolle): bool
    {
        return match ($zugriff->art) {
            ZugriffArt::Oeffentlich, ZugriffArt::Angemeldet => true,
            // A login is no cron token (App\Http\Routen).
            ZugriffArt::Cron => false,
            ZugriffArt::Recht => $zugriff->recht instanceof Permission && $rolle->darf($zugriff->recht),
        };
    }

    private function anmelden(Rolle $rolle): void
    {
        $this->konten->anmelden(new Konto(7, 'Test', $rolle));
    }

    private function aufrufen(Router $router, HttpMethod $methode, string $pfad, bool $mitToken = true): Response
    {
        $treffer = $router->match($methode, $pfad);
        self::assertNotNull($treffer->handler, $methode->value . ' ' . $pfad . ' is not routed');

        $kopf = [];
        if ($mitToken && $methode !== HttpMethod::Get) {
            $session = new Session();
            $session->start();
            $kopf['x-csrf-token'] = $session->csrfToken();
        }

        $antwort = ($treffer->handler)(new Request($methode, $pfad, headers: $kopf), $treffer->params);
        self::assertInstanceOf(Response::class, $antwort);

        return $antwort;
    }

    private static function finde(Router $router, string $methode, string $muster): Route
    {
        foreach ($router->routes() as $route) {
            if ($route->method->value === $methode && $route->pattern === $muster) {
                return $route;
            }
        }

        self::fail('no route ' . $methode . ' ' . $muster);
    }

    /** A path that matches the pattern: each placeholder gets a fitting value. */
    private static function beispielPfad(string $muster): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}/',
            static function (array $m): string {
                $regex = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';
                foreach (['7', 'beispiel', 'x'] as $kandidat) {
                    if (preg_match('#^(?:' . $regex . ')$#', $kandidat) === 1) {
                        return $kandidat;
                    }
                }

                self::fail('no sample value for {' . $m[1] . '}');
            },
            $muster,
        );
    }
}
