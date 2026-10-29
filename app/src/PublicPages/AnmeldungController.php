<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Dienste;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Repository\BenutzerRepository;
use App\Service\Account\Anmeldung;
use App\Service\Account\Einladung;
use App\Service\Account\Kontomails;
use App\Service\Account\PasswordPolicy;
use App\Service\Account\PasswortReset;
use App\View\Area;
use App\View\Navigation;

/**
 * The way in (docs/spec/02-sicherheit.md section 3, E-F3): login, logout,
 * "Passwort vergessen" and accepting an invitation. Taken over from
 * Vereinsbelege's AuthController and PasswordController, without the vault.
 *
 * The HTTP half only: the services under App\Service\Account check, count
 * and write; this class turns their answers into a session, a page or a
 * redirect. Every page here renders in Area::Oeffentlich - somebody who is
 * not logged in has no business seeing the navigation.
 *
 * The second factor (issue #8) hooks in at the marked place in anmelden(),
 * between "password correct" and "session opened".
 */
final readonly class AnmeldungController
{
    public const string VERGESSEN = '/anmelden/passwort-vergessen';
    public const string PASSWORT_NEU = '/anmelden/passwort-neu';
    public const string EINLADUNG = '/anmelden/einladung';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function form(Request $request): Response
    {
        $this->dienste->session->start();
        if ($this->dienste->session->userId() !== null && $request->feld('abgelaufen') !== '1') {
            // The guard behind /app decides whether that session still holds.
            return Response::redirect('/app');
        }

        return $this->anmeldeseite(
            weiter: self::weiter($request->feld('weiter')),
            hinweis: $request->feld('abgelaufen') === '1' ? 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich neu an.' : null,
        );
    }

    public function anmelden(Request $request): Response
    {
        $email = $request->feld('email');
        $weiter = self::weiter($request->feld('weiter'));

        $ergebnis = Anmeldung::aus($this->dienste)->versuch($email, self::roh($request, 'passwort'), $request->ip);
        if (!$ergebnis->istErfolg()) {
            // The address stays in the field so that a typo in the password
            // does not cost the whole form - it is what the user just
            // typed, not something the server looked up.
            return $this->anmeldeseite(weiter: $weiter, fehler: $ergebnis->fehler?->meldung(), email: $email, status: 422);
        }
        $benutzer = $ergebnis->benutzer;

        // Issue #8: the second factor goes here.

        $session = $this->dienste->session;
        $session->login($benutzer->id, epoch: $benutzer->sessionEpoch);
        Anmeldung::aus($this->dienste)->abgeschlossen($benutzer->id);

        return Response::redirect($weiter ?? Navigation::startseiteFuer($benutzer->konto()));
    }

    /**
     * Public: logging out needs no valid session, and it is a POST with a
     * CSRF token - a GET that ends a session can be triggered by any image
     * tag.
     */
    public function abmelden(Request $request): Response
    {
        $this->dienste->session->destroy();

        return Response::redirect(LoginGuard::ANMELDUNG);
    }

    public function vergessenForm(Request $request): Response
    {
        return $this->seite('anmelden/passwort-vergessen', 'Passwort vergessen');
    }

    /**
     * The same answer whether or not the address has an account (no user
     * enumeration); only "too many requests" differs, and that answer is
     * the same for every address too.
     */
    public function vergessen(Request $request): Response
    {
        $anfrage = PasswortReset::aus($this->dienste)->anfordern($request->feld('email'), $request->ip);
        if ($anfrage->zuVieleVersuche) {
            return $this->seite('anmelden/passwort-vergessen', 'Passwort vergessen', [
                'fehler' => 'Zu viele Anfragen. Bitte warten Sie 15 Minuten und versuchen Sie es dann erneut.',
            ], 429);
        }
        if ($anfrage->email !== null && $anfrage->token !== null) {
            try {
                Kontomails::aus($this->dienste)->resetLink($request, $anfrage->email, $anfrage->token);
            } catch (\Throwable $e) {
                // An error page here would tell that the address has an
                // account. The log names the class only.
                $this->dienste->logger->append(sprintf('password reset mail not queued: %s', $e::class));
            }
        }

        return $this->seite('anmelden/passwort-vergessen', 'Passwort vergessen', ['gesendet' => true]);
    }

    public function neuForm(Request $request): Response
    {
        $reset = PasswortReset::aus($this->dienste);
        $token = $this->tokenAusLink($request, PasswortReset::TYP, self::PASSWORT_NEU);
        if ($token instanceof Response) {
            return $token;
        }
        if (!$reset->nutzbar($token)) {
            return $this->linkUngueltig('Link abgelaufen', PasswortReset::linkUngueltig(), self::VERGESSEN, 'Neuen Link anfordern');
        }

        return $this->passwortSeite('anmelden/passwort-neu', 'Neues Passwort festlegen');
    }

    public function neu(Request $request): Response
    {
        $session = $this->dienste->session;
        $ergebnis = PasswortReset::aus($this->dienste)->abschliessen(
            $session->einmalLink(PasswortReset::TYP),
            self::roh($request, 'passwort'),
            self::roh($request, 'passwort_wiederholung'),
        );
        if (!$ergebnis->istErfolg()) {
            if ($ergebnis->fehler === [PasswortReset::linkUngueltig()]) {
                return $this->linkUngueltig('Link abgelaufen', PasswortReset::linkUngueltig(), self::VERGESSEN, 'Neuen Link anfordern');
            }

            return $this->passwortSeite('anmelden/passwort-neu', 'Neues Passwort festlegen', $ergebnis->fehler);
        }

        $benutzer = new BenutzerRepository($this->dienste->pdo())->findById($ergebnis->userId);
        if ($benutzer !== null) {
            Kontomails::aus($this->dienste)->hinweis($benutzer->email, Kontomails::PASSWORT_ZURUECKGESETZT);
        }

        // Every session of the account just ended - this browser's too, if
        // it had one.
        $session->destroy();
        $session->start();
        $session->flash('Ihr neues Passwort ist gespeichert. Bitte melden Sie sich damit an.');

        return Response::redirect(LoginGuard::ANMELDUNG);
    }

    public function einladungForm(Request $request): Response
    {
        $token = $this->tokenAusLink($request, Einladung::TYP, self::EINLADUNG);
        if ($token instanceof Response) {
            return $token;
        }
        if (!Einladung::aus($this->dienste)->nutzbar($token)) {
            return $this->linkUngueltig('Einladung abgelaufen', Einladung::linkUngueltig());
        }

        return $this->passwortSeite('anmelden/einladung', 'Einladung annehmen');
    }

    public function einladung(Request $request): Response
    {
        $session = $this->dienste->session;
        $ergebnis = Einladung::aus($this->dienste)->annehmen(
            $session->einmalLink(Einladung::TYP),
            self::roh($request, 'passwort'),
            self::roh($request, 'passwort_wiederholung'),
        );
        if (!$ergebnis->istErfolg()) {
            if ($ergebnis->fehler === [Einladung::linkUngueltig()]) {
                return $this->linkUngueltig('Einladung abgelaufen', Einladung::linkUngueltig());
            }

            return $this->passwortSeite('anmelden/einladung', 'Einladung annehmen', $ergebnis->fehler);
        }

        $session->destroy();
        $session->start();
        $session->flash('Ihr Passwort ist gespeichert. Melden Sie sich jetzt damit an.');

        return Response::redirect(LoginGuard::ANMELDUNG);
    }

    /**
     * The link from the mail arrives as `?token=…`: the token moves into
     * the session and the browser is sent on to the bare path (Session::
     * merkeEinmalLink()), so the form page - and every asset it loads -
     * has no token in its URL or Referer. Without a token in the query the
     * one in the session counts.
     */
    private function tokenAusLink(Request $request, string $typ, string $pfad): string|Response
    {
        $session = $this->dienste->session;
        $session->start();
        $token = $request->feld('token');
        if ($token !== '') {
            $session->merkeEinmalLink($typ, $token);

            return Response::redirect($pfad, 303);
        }

        return $session->einmalLink($typ);
    }

    /**
     * The "where to afterwards" value, accepted only as a plain path on this
     * installation: it comes from the query string, so an open redirect is
     * one careless concatenation away. "//host" is a foreign host to a
     * browser, which is why only the restricted character set passes.
     */
    public static function weiter(string $wert): ?string
    {
        if (!str_starts_with($wert, '/') || str_starts_with($wert, '//') || str_starts_with($wert, '/anmelden')) {
            return null;
        }

        return preg_match('#^/[A-Za-z0-9\-._~/]*$#D', $wert) === 1 ? $wert : null;
    }

    /** A password field exactly as typed - spaces may belong to it. */
    private static function roh(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }

    private function anmeldeseite(?string $weiter, ?string $fehler = null, ?string $hinweis = null, string $email = '', int $status = 200): Response
    {
        return $this->seite('anmelden', 'Anmelden', [
            'weiter' => $weiter,
            'fehler' => $fehler,
            'hinweis' => $hinweis,
            'email' => $email,
        ], $status);
    }

    /**
     * @param list<string> $fehler
     */
    private function passwortSeite(string $template, string $titel, array $fehler = []): Response
    {
        return $this->seite($template, $titel, [
            'fehler' => $fehler,
            'minLaenge' => PasswordPolicy::MIN_LAENGE,
        ], $fehler === [] ? 200 : 422);
    }

    private function linkUngueltig(string $titel, string $meldung, ?string $ziel = null, ?string $zielText = null): Response
    {
        return $this->seite('anmelden/link-ungueltig', $titel, [
            'meldung' => $meldung,
            'ziel' => $ziel,
            'zielText' => $zielText,
        ], 410);
    }

    /**
     * Every page here has a session: its form needs a CSRF token, and the
     * login page shows the flash of the step before (reset, invitation).
     *
     * @param array<string, mixed> $daten
     */
    private function seite(string $template, string $titel, array $daten = [], int $status = 200): Response
    {
        $session = $this->dienste->session;
        $session->start();

        return Response::html($this->dienste->view->render($template, [
            'title' => $titel,
            'csrf' => $session->csrfToken(),
            'flash' => $session->pullFlash(),
            ...$daten,
        ], Area::Oeffentlich), $status);
    }
}
