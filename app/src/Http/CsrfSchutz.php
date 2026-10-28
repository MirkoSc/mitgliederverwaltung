<?php

declare(strict_types=1);

namespace App\Http;

use App\View\Area;
use App\View\View;

/**
 * The CSRF check of every writing route, in one place (CLAUDE.md section 4,
 * docs/spec/02-sicherheit.md section 7). Taken over from Vereinsbelege.
 *
 * App\Http\Routen wraps every POST handler in pruefe() - except the cron
 * routes, which carry no session and are protected by their token. A route
 * file cannot forget it; tests/Http/RoutenZugriffTest.php sends every POST
 * route a request without token.
 *
 * Order: behind App\Http\LoginGuard for protected routes - an anonymous
 * request still gets the login (or 401), a logged-in one without a valid
 * token 403.
 */
final readonly class CsrfSchutz
{
    public function __construct(
        private Session $session,
        private View $view,
    ) {
    }

    public static function noetig(HttpMethod $methode, Zugriff $zugriff): bool
    {
        return $methode !== HttpMethod::Get && $zugriff->art !== ZugriffArt::Cron;
    }

    /**
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     * @return \Closure(Request, array<string, string>): ResponseInterface
     */
    public function pruefe(bool $api, \Closure $handler): \Closure
    {
        return function (Request $request, array $params = []) use ($api, $handler): ResponseInterface {
            $this->session->start();

            return $this->session->checkCsrf($request)
                ? $handler($request, $params)
                : $this->abweisen($request, $api);
        };
    }

    private function abweisen(Request $request, bool $api): ResponseInterface
    {
        // Script-driven requests answer in JSON even on routes that are not
        // declared API: step chains read the answer with json(). They - and
        // htmx - send the token as a header; a plain form never does.
        if ($api || $request->header('x-csrf-token') !== null || str_contains($request->header('accept') ?? '', 'application/json')) {
            return Response::json(['fehler' => 'Das Sicherheits-Token fehlt oder ist abgelaufen. Bitte laden Sie die Seite neu.'], 403);
        }

        return Response::html($this->view->render('fehler', [
            'title' => 'Formular abgelaufen',
            'meldung' => 'Das Formular war nicht mehr gültig, etwa weil Sie sich zwischendurch neu angemeldet haben. '
                . 'Bitte laden Sie die Seite neu und versuchen Sie es noch einmal.',
        ], Area::Oeffentlich), 403);
    }
}
