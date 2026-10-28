<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Konto;
use App\View\Area;
use App\View\View;

/**
 * The gate in front of everything that is not public: /app and /admin.
 * Taken over from Vereinsbelege, without vault, scopes and the second-factor
 * setup (that comes with issue #8).
 *
 * It answers two questions server side, for the route, not by hiding a link
 * (CLAUDE.md section 4): may this request happen at all, and may THIS
 * account do what the route does? The second question is the route's access
 * declaration (App\Http\Zugriff); App\Http\Routen builds each protected
 * route's wrapper from that declaration through pruefe(), so what a route
 * declares is what is checked.
 *
 * A logged-in account without the right gets 403, not the login form.
 *
 * Expiry is part of the gate (docs/spec/02-sicherheit.md section 4): idle
 * 30 minutes (60 in the Barrierefrei-Modus), 12 hours absolute, and a
 * session whose account changed its `session_epoch` is over as well.
 */
final readonly class LoginGuard
{
    /**
     * Sent by requests the page makes on its own (polling a step chain);
     * they leave the idle timeout where it is.
     */
    public const string HINTERGRUND_HEADER = 'x-hintergrund';

    public const int LEERLAUF_SEKUNDEN = 30 * 60;

    /** The Barrierefrei-Modus doubles the idle time (06 section 4). */
    public const int LEERLAUF_BARRIEREFREI_SEKUNDEN = 60 * 60;

    public const int HOECHSTDAUER_SEKUNDEN = 12 * 60 * 60;

    public const string ANMELDUNG = '/anmelden';

    public function __construct(
        private Session $session,
        private View $view,
        private Kontoquelle $konten,
    ) {
    }

    /**
     * Wraps a handler in the check its route declares.
     *
     * @param \Closure(Request, array<string, string>): ResponseInterface $handler
     * @return \Closure(Request, array<string, string>): ResponseInterface
     */
    public function pruefe(Zugriff $zugriff, \Closure $handler): \Closure
    {
        return fn(Request $request, array $params = []): ResponseInterface => $this->check($request, $zugriff)
            ?? $handler($request, $params);
    }

    /** The idle limit for this account (06 section 4). */
    public static function leerlaufSekunden(Konto $konto): int
    {
        return $konto->barrierefrei ? self::LEERLAUF_BARRIEREFREI_SEKUNDEN : self::LEERLAUF_SEKUNDEN;
    }

    /**
     * @return ResponseInterface|null null when the request may proceed
     */
    private function check(Request $request, Zugriff $zugriff): ?ResponseInterface
    {
        $this->session->start();

        $userId = $this->session->userId();
        if ($userId === null) {
            return $this->abweisen($request, $zugriff->api, abgelaufen: false);
        }

        // Three ways a live session stops being one: it ran out, the account
        // behind it is gone or locked, or its password was reset or changed
        // elsewhere since this session logged in. All three end it here.
        $konto = $this->konten->konto($userId);
        if (
            $konto === null
            || $this->session->isExpired(self::leerlaufSekunden($konto), self::HOECHSTDAUER_SEKUNDEN)
            || $this->session->epoch() !== $konto->sessionEpoch
        ) {
            $this->session->destroy();

            return $this->abweisen($request, $zugriff->api, abgelaufen: true);
        }

        // A background poll is no sign of a person at the screen: counting
        // it would keep an abandoned session alive until the absolute limit.
        if ($request->header(self::HINTERGRUND_HEADER) !== '1') {
            $this->session->touch();
        }

        // The chrome of every page behind the login: who is signed in, and
        // the token their forms need.
        $this->view->setAnmeldung($konto, $this->session->csrfToken());

        if (!$this->darf($konto, $zugriff)) {
            return $this->verweigern($zugriff->api);
        }

        return null;
    }

    private function darf(Konto $konto, Zugriff $zugriff): bool
    {
        return match ($zugriff->art) {
            ZugriffArt::Oeffentlich, ZugriffArt::Cron, ZugriffArt::Angemeldet => true,
            ZugriffArt::Recht => $zugriff->recht !== null && $konto->darf($zugriff->recht),
        };
    }

    private function verweigern(bool $api): ResponseInterface
    {
        if ($api) {
            return Response::json(['fehler' => 'Keine Berechtigung.'], 403);
        }

        return Response::html($this->view->render('fehler', [
            'title' => 'Keine Berechtigung',
            'meldung' => 'Diese Seite ist dem Admin vorbehalten. Wenden Sie sich an ihn, wenn Sie etwas darauf brauchen.',
        ], Area::App), 403);
    }

    private function abweisen(Request $request, bool $api, bool $abgelaufen): ResponseInterface
    {
        if ($api) {
            return Response::json(['fehler' => 'Nicht angemeldet.'], 401);
        }

        return Response::redirect(self::ANMELDUNG . self::weiter($request, $abgelaufen));
    }

    /**
     * Where to return to after the login. Only GET requests are worth
     * remembering - replaying a POST after a login would repeat a write the
     * user has long forgotten about. A path, never a full URL (no open
     * redirect).
     */
    private static function weiter(Request $request, bool $abgelaufen): string
    {
        $parameter = [];
        if ($request->method === HttpMethod::Get && str_starts_with($request->path, '/') && !str_starts_with($request->path, '//')) {
            $parameter['weiter'] = $request->path;
        }
        if ($abgelaufen) {
            $parameter['abgelaufen'] = '1';
        }

        return $parameter === [] ? '' : '?' . http_build_query($parameter);
    }
}
