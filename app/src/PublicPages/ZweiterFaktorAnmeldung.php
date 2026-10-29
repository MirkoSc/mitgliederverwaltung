<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Benutzer;
use App\Domain\MfaMethode;
use App\Http\Cookie;
use App\Http\LoginGuard;
use App\Http\Request;
use App\Http\Response;
use App\Repository\BenutzerRepository;
use App\Service\Account\Anmeldung;
use App\Service\Account\Kontomails;
use App\Service\Account\Nachweis;
use App\Service\Account\ZweiterFaktor;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * The second step of the login (docs/spec/02-sicherheit.md section 3):
 * between "password correct" (App\PublicPages\AnmeldungController::
 * anmelden()) and an open session. Taken over from Vereinsbelege's
 * AuthController/MfaController/LoginCompleter, without the vault - the
 * login in progress waits in the session (Session::merkeOffeneAnmeldung()),
 * not in a sealed cookie.
 *
 * Three ways past the password:
 *   - no second factor set up yet: the session opens and the login guard
 *     allows nothing but the setup ("Erzwungene Einrichtung");
 *   - a remembered device (cookie GERAET_COOKIE, 30 days): straight in;
 *   - otherwise /anmelden/bestaetigen asks for a code from the app, from
 *     the mail (sent the moment the password was right) or a backup code.
 */
final readonly class ZweiterFaktorAnmeldung
{
    public const string BESTAETIGEN = '/anmelden/bestaetigen';

    public const string CODE_SENDEN = '/anmelden/bestaetigen/code-senden';

    /** `__Host-geraet` over HTTPS. */
    public const string GERAET_COOKIE = 'geraet';

    /** How long a password-checked login waits for its code - as long as a mail code lives. */
    public const int OFFEN_SEKUNDEN = ZweiterFaktor::CODE_GUELTIG_SEKUNDEN;

    private const string ZU_VIELE = 'Zu viele Versuche. Bitte warten Sie 15 Minuten und versuchen Sie es dann erneut.';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    /** Called once the password was right. */
    public function nachPasswort(Request $request, Benutzer $benutzer, ?string $weiter): Response
    {
        if ($benutzer->mfaMethod === null) {
            return $this->abschliessen($request, $benutzer, $benutzer->sessionEpoch, $weiter, 'keiner');
        }

        $faktor = ZweiterFaktor::aus($this->dienste);
        $https = Request::httpsFromGlobals();
        if ($faktor->geraetGemerkt($benutzer->id, Cookie::hostPrefixedFrom($request, self::GERAET_COOKIE, $https))) {
            return $this->abschliessen($request, $benutzer, $benutzer->sessionEpoch, $weiter, 'geraet');
        }

        $session = $this->dienste->session;
        $session->merkeOffeneAnmeldung($benutzer->id, $benutzer->sessionEpoch, $weiter);
        if ($benutzer->mfaMethod === MfaMethode::Email) {
            $this->sendeCode($benutzer);
        }

        return Response::redirect(self::BESTAETIGEN);
    }

    public function form(Request $request): Response
    {
        $session = $this->dienste->session;
        $session->start();
        $offen = $session->offeneAnmeldung(self::OFFEN_SEKUNDEN);
        if ($offen === null) {
            return Response::redirect(LoginGuard::ANMELDUNG);
        }
        $benutzer = $this->benutzer($offen['user_id']);
        if ($benutzer === null) {
            return $this->vonVorn();
        }

        return $this->seite($benutzer);
    }

    public function pruefen(Request $request): Response
    {
        $session = $this->dienste->session;
        $offen = $session->offeneAnmeldung(self::OFFEN_SEKUNDEN);
        $benutzer = $offen === null ? null : $this->benutzer($offen['user_id']);
        if ($offen === null || $benutzer === null) {
            return $this->vonVorn();
        }

        $faktor = ZweiterFaktor::aus($this->dienste);
        if (!$faktor->versuch($request->ip, $benutzer->id)) {
            return $this->seite($benutzer, fehler: self::ZU_VIELE, status: 429);
        }
        $nachweis = $faktor->pruefe($benutzer->id, self::roh($request, 'code'));
        if ($nachweis === null) {
            $this->dienste->audit()->protokollieren(AuditAktion::AnmeldungFehlgeschlagen, null, 'user', $benutzer->id, ['schritt' => 'zweiter_faktor'], $request->ip);

            return $this->seite($benutzer, fehler: 'Der Code ist ungültig oder abgelaufen.', status: 422);
        }
        $faktor->erfolg($benutzer->id);

        $antwort = $this->abschliessen($request, $benutzer, $offen['epoch'], $offen['weiter'], match ($nachweis) {
            Nachweis::App => 'app',
            Nachweis::Email => 'email',
            Nachweis::BackupCode => 'backup_code',
        });

        if ($nachweis === Nachweis::BackupCode) {
            // A backup code stands in for a lost phone or mailbox - not a
            // device to trust for a month.
            $uebrig = $faktor->offeneBackupCodes($benutzer->id);
            $session->flash(sprintf(
                'Sie haben sich mit einem Backup-Code angemeldet. Es %s noch %d übrig. Neue Codes erzeugen Sie unter „Zweiter Faktor“.',
                $uebrig === 1 ? 'ist' : 'sind',
                $uebrig,
            ), $uebrig <= 3 ? FlashArt::Warnung : FlashArt::Info);

            return $antwort;
        }

        if (($request->post['geraet_merken'] ?? '') === '1') {
            $https = Request::httpsFromGlobals();
            $antwort = $antwort->withCookie(Cookie::hostPrefixed(
                self::GERAET_COOKIE,
                $faktor->merkeGeraet($benutzer->id),
                $https,
                ZweiterFaktor::GERAET_TAGE * 86400,
            ));
            Kontomails::aus($this->dienste)->hinweis($benutzer->email, Kontomails::NEUES_GERAET);
        }

        return $antwort;
    }

    /** "Code erneut senden" - only for an account whose factor is the mail. */
    public function codeSenden(Request $request): Response
    {
        $session = $this->dienste->session;
        $offen = $session->offeneAnmeldung(self::OFFEN_SEKUNDEN);
        $benutzer = $offen === null ? null : $this->benutzer($offen['user_id']);
        if ($benutzer === null) {
            return $this->vonVorn();
        }
        if ($benutzer->mfaMethod === MfaMethode::Email) {
            $this->sendeCode($benutzer);
        }

        return Response::redirect(self::BESTAETIGEN);
    }

    /**
     * Opens the session - the one place every way through the login ends.
     *
     * @param int $epoch the account's `session_epoch` when the password was
     *        checked, not now: a reset while the code was pending must end
     *        this session too (App\Http\LoginGuard)
     */
    private function abschliessen(Request $request, Benutzer $benutzer, int $epoch, ?string $weiter, string $weg): Response
    {
        $this->dienste->session->login($benutzer->id, epoch: $epoch);
        Anmeldung::aus($this->dienste)->abgeschlossen($benutzer->id);
        $this->dienste->audit()->protokollieren(AuditAktion::AnmeldungErfolgreich, $benutzer->id, 'user', $benutzer->id, ['zweiter_faktor' => $weg], $request->ip);

        return Response::redirect($weiter ?? Navigation::startseiteFuer($benutzer->konto()));
    }

    /**
     * Sends a fresh code; what went wrong goes to the confirmation page as a
     * flash, the login itself goes on ("Code erneut senden" is right there).
     */
    private function sendeCode(Benutzer $benutzer): void
    {
        $session = $this->dienste->session;
        $code = ZweiterFaktor::aus($this->dienste)->neuerMailCode($benutzer->id);
        if ($code === null) {
            $session->flash('Es wurden zu viele Codes angefordert. Bitte warten Sie 15 Minuten. Ein Backup-Code geht sofort.', FlashArt::Warnung);

            return;
        }

        try {
            $ergebnis = Kontomails::aus($this->dienste)->code($benutzer->email, $code);
            $gesendet = $ergebnis->erfolg;
        } catch (\Throwable $e) {
            $this->dienste->logger->append(sprintf('second factor code not queued: %s', $e::class));
            $gesendet = false;
        }
        $session->flash(
            $gesendet
                ? 'Wir haben Ihnen einen Code per E-Mail geschickt.'
                : 'Der Code konnte gerade nicht verschickt werden. Versuchen Sie „Code erneut senden“ oder nehmen Sie einen Backup-Code.',
            $gesendet ? FlashArt::Info : FlashArt::Warnung,
        );
    }

    /** The account behind an open login - if it may still log in at all. */
    private function benutzer(int $userId): ?Benutzer
    {
        $benutzer = new BenutzerRepository($this->dienste->pdo())->findById($userId);

        return $benutzer !== null && $benutzer->darfAnmelden() && $benutzer->mfaMethod !== null ? $benutzer : null;
    }

    private function vonVorn(): Response
    {
        $session = $this->dienste->session;
        $session->start();
        $session->vergesseOffeneAnmeldung();
        $session->flash('Die Anmeldung hat zu lange gedauert. Bitte melden Sie sich neu an.', FlashArt::Warnung);

        return Response::redirect(LoginGuard::ANMELDUNG);
    }

    private function seite(Benutzer $benutzer, ?string $fehler = null, int $status = 200): Response
    {
        $session = $this->dienste->session;

        return Response::html($this->dienste->view->render('anmelden/bestaetigen', [
            'title' => 'Anmeldung bestätigen',
            'csrf' => $session->csrfToken(),
            'flash' => $session->pullFlash(),
            'methode' => $benutzer->mfaMethod,
            'fehler' => $fehler,
            'geraetTage' => ZweiterFaktor::GERAET_TAGE,
        ], Area::Oeffentlich), $status);
    }

    private static function roh(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }
}
