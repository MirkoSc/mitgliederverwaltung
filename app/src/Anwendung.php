<?php

declare(strict_types=1);

namespace App;

use App\Http\CsrfSchutz;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\Http\Zugriff;
use App\Service\MaintenanceMode;
use App\Support\FileLogger;
use App\View\Area;
use App\View\View;

/**
 * Builds the kernel from the services - shared by app/src/bootstrap.php and
 * the feature tests, so the tests run exactly the application's own wiring:
 * every route file under app/routes/, the real login guard, the real CSRF
 * check.
 */
final class Anwendung
{
    /**
     * @param (\Closure(HttpMethod, string, Zugriff, \Closure): \Closure)|null $ersatz
     *        tests only, see App\Http\Routen
     */
    public static function kernel(Dienste $dienste, ?\Closure $ersatz = null): Kernel
    {
        $router = new Router();
        $routen = new Routen(
            $router,
            $dienste,
            new LoginGuard($dienste->session, $dienste->view, $dienste->kontoquelle()),
            new CsrfSchutz($dienste->session, $dienste->view),
            $ersatz,
        );
        $routen->ladeDateien($dienste->paths->routesDir());

        $view = $dienste->view;
        $view->setRouteVorhanden(static fn(HttpMethod $methode, string $pfad): bool => $router->hat($methode, $pfad));
        $session = $dienste->session;
        $view->setFlashQuelle(static fn() => session_status() === PHP_SESSION_ACTIVE ? $session->pullFlash() : null);

        // Resolved when a page is rendered, not now: no file access per request
        // for routes that render nothing.
        $view->setWartungQuelle(static fn() => MaintenanceMode::aus($dienste)->state());

        return new Kernel(
            router: $router,
            // Asset URLs carry ?v=<VERSION>, so a release may cache them for
            // good; a development version revalidates instead.
            staticFiles: new StaticFileHandler($dienste->paths->publicDir(), longCache: !$dienste->version->isDev()),
            view: $view,
            debug: $dienste->config->debug,
            logger: $dienste->logger,
        );
    }

    /**
     * Without shared/config.php there is no database, so nothing but the
     * installer can run (docs/spec/01-plattform.md section 3). The installer
     * comes with issue #3; until then every page says what is missing.
     */
    public static function ohneKonfiguration(string $publicDir, View $view, FileLogger $logger): Kernel
    {
        $router = new Router();
        $router->get('/{rest:.*}', Zugriff::oeffentlich(), static fn(): Response => Response::html(
            $view->render('fehler', [
                'title' => 'Noch nicht eingerichtet',
                'meldung' => 'Die Mitgliederverwaltung ist auf diesem Webspace noch nicht eingerichtet: '
                    . 'Es fehlt die Datei shared/config.php.',
            ], Area::Oeffentlich),
            503,
        ));

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($publicDir, longCache: false),
            view: $view,
            debug: false,
            logger: $logger,
        );
    }
}
