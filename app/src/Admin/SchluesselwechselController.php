<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Repository\BenutzerRepository;
use App\Service\Account\PasswordHasher;
use App\Service\Crypto\Schluesselwechsel;
use App\Service\Crypto\SchluesselwechselLaeuftBereits;
use App\Service\Crypto\SchluesselwechselNichtMoeglich;
use App\Service\RateLimiter;

/**
 * The key change in the settings section System (issue #80,
 * App\Service\Crypto\Schluesselwechsel): its fragment, the start - only
 * with the admin's password typed again - and one step of the chain per
 * request. While a change runs, the fragment asks for the next step itself
 * (htmx, like the integrity check), so no script of its own is needed.
 */
final readonly class SchluesselwechselController
{
    private const string ZIEL = '/admin#system';

    /** Rate limit of the password re-entry, per account. */
    public const string LIMIT = 'schluesselwechsel';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function zeigen(Request $request): Response
    {
        return $this->antwort($request);
    }

    public function starten(Request $request): Response
    {
        $fehler = $this->passwortFehler($request);
        if ($fehler === null && $request->feld('verstanden') !== '1') {
            $fehler = 'Bitte bestätigen Sie, dass Sie die Hinweise gelesen haben.';
        }
        if ($fehler !== null) {
            return $this->antwort($request, fehler: $fehler);
        }

        try {
            $this->wechsel()->starten($this->dienste->session->userId());
        } catch (SchluesselwechselNichtMoeglich | SchluesselwechselLaeuftBereits $e) {
            return $this->antwort($request, fehler: $e->getMessage());
        }

        return $this->antwort($request);
    }

    public function schritt(Request $request): Response
    {
        try {
            $schluessel = $this->wechsel()->schritt();
        } catch (SchluesselwechselLaeuftBereits) {
            // Two tabs: the other one drives the chain; this one only looks
            // again in a while instead of knocking at the lock in a loop.
            return $this->antwort($request, verzoegert: true);
        } catch (\Throwable $e) {
            // Only the class goes to the log (CLAUDE.md section 4). Nothing
            // is lost: config.php still holds both keys, the next step goes on.
            $this->dienste->logger->append('Key change step failed: ' . $e::class);

            return $this->antwort($request, fehler: $e instanceof SchluesselwechselNichtMoeglich
                ? $e->getMessage()
                : 'Der Schritt ist fehlgeschlagen. Es ist nichts verloren: „Fortsetzen“ macht an derselben Stelle weiter.');
        }

        return $this->antwort($request, schluessel: $schluessel);
    }

    /** The new key once more, after the password - until it is confirmed as printed. */
    public function anzeigen(Request $request): Response
    {
        $fehler = $this->passwortFehler($request);

        return $fehler !== null
            ? $this->antwort($request, fehler: $fehler)
            : $this->antwort($request, schluessel: $this->wechsel()->schluesselAnzeigen());
    }

    public function ausgedruckt(Request $request): Response
    {
        try {
            $this->wechsel()->ausgedruckt();
        } catch (SchluesselwechselLaeuftBereits $e) {
            return $this->antwort($request, fehler: $e->getMessage());
        }

        return $this->antwort($request);
    }

    private function passwortFehler(Request $request): ?string
    {
        $userId = $this->dienste->session->userId();
        $benutzer = $userId === null ? null : new BenutzerRepository($this->dienste->pdo())->findById($userId);
        if ($benutzer === null) {
            return 'Das Passwort stimmt nicht.';
        }
        $limits = RateLimiter::aus($this->dienste);
        if (!$limits->versuch(self::LIMIT, (string) $benutzer->id, RateLimiter::LOGIN_JE_KONTO)) {
            return 'Zu viele Versuche. Bitte warten Sie 15 Minuten und versuchen Sie es dann erneut.';
        }
        // untrimmed, as the login reads it
        $passwort = $request->post['passwort'] ?? '';
        if (!new PasswordHasher()->verify(is_string($passwort) ? $passwort : '', $benutzer->passwordHash)) {
            return 'Das Passwort stimmt nicht.';
        }
        $limits->reset(self::LIMIT, (string) $benutzer->id);

        return null;
    }

    /**
     * Always 200: htmx swaps no error answers, and the fragment is what
     * says what went wrong.
     */
    private function antwort(Request $request, ?string $fehler = null, ?string $schluessel = null, bool $verzoegert = false): Response
    {
        if (!$request->istHtmx()) {
            return Response::redirect(self::ZIEL);
        }
        $wechsel = $this->wechsel();
        $stand = $wechsel->stand();
        $laeuft = $wechsel->offen();
        $fertig = !$laeuft && $stand !== null && $stand['phase'] === Schluesselwechsel::PHASE_FERTIG;

        return Response::html($this->dienste->view->fragment('admin/einstellungen/schluesselwechsel', [
            'stand' => $stand,
            'laeuft' => $laeuft,
            // the chain drives itself on; not after an error, so a failing
            // step is not hammered in a loop
            'weiter' => $laeuft && $fehler === null,
            'verzoegert' => $verzoegert,
            'fertig' => $fertig,
            'unbestaetigt' => $fertig && ($stand['ausgedruckt'] ?? false) !== true,
            'wartezeit' => $laeuft && $stand !== null ? $wechsel->wartezeit($stand) : 0,
            'fehler' => $fehler,
            'schluessel' => $schluessel,
        ]));
    }

    private function wechsel(): Schluesselwechsel
    {
        return Schluesselwechsel::aus($this->dienste);
    }
}
