<?php

declare(strict_types=1);

namespace App;

use App\Config\Paths;
use App\Http\CsrfSchutz;
use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\LoginGuard;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Router;
use App\Http\Session;
use App\Http\StaticFileHandler;
use App\Http\Zugriff;
use App\Installer\InstallController;
use App\Service\Mail\MailTransport;
use App\Service\Verein\LogoService;
use App\Service\MaintenanceMode;
use App\Support\FileLogger;
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
        $view->setLogoQuelle(static fn(): ?int => LogoService::aus($dienste)->version());
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
     * installer runs (docs/spec/01-plattform.md section 3, 04 section 2):
     * the /install routes, every other path redirects there. Every POST
     * passes the same CSRF check as the application's own routes.
     *
     * @param (\Closure(string): bool)|null $istUpload tests only, see InstallController
     */
    public static function installation(
        Paths $paths,
        View $view,
        FileLogger $logger,
        ?\Closure $istUpload = null,
        ?MailTransport $mailTransport = null,
    ): Kernel {
        $session = new Session();
        $installer = new InstallController($view, $paths, $session, $istUpload, $mailTransport);
        $csrf = new CsrfSchutz($session, $view);
        // No account exists yet; what guards these routes is the
        // installation token (InstallController) and, afterwards, the
        // config.php they write.
        $offen = Zugriff::oeffentlich();

        $router = new Router();
        $router->get('/install', $offen, $installer->zeigen(...));
        foreach ([
            '/install/freigabe' => $installer->freigabe(...),
            '/install/datenbank' => $installer->datenbank(...),
            '/install/art' => $installer->art(...),
            '/install/admin' => $installer->admin(...),
            '/install/schluessel' => $installer->schluessel(...),
            '/install/mail' => $installer->mail(...),
            '/install/wiederherstellen' => $installer->wiederherstellen(...),
            '/install/wiederherstellen/schritt' => $installer->wiederherstellenSchritt(...),
            '/install/neu' => $installer->neu(...),
        ] as $pfad => $handler) {
            $router->post($pfad, $offen, $csrf->pruefe(false, $handler));
        }
        // Last: the router takes the first matching route, this one matches all.
        $router->get('/{rest:.*}', $offen, static fn(): Response => Response::redirect('/install'));

        return new Kernel(
            router: $router,
            staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
            view: $view,
            // Never debug mode here: an unhandled exception would render the
            // full exception string, and on this path that may carry the
            // database password just typed in.
            debug: false,
            logger: $logger,
        );
    }
}
