<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Benutzer;
use App\Domain\MfaMethode;
use App\Http\Cookie;
use App\Http\Request;
use App\Http\Response;
use App\PublicPages\ZweiterFaktorAnmeldung;
use App\Repository\BenutzerRepository;
use App\Service\Account\Kontomails;
use App\Service\Account\Totp;
use App\Service\Account\ZweiterFaktor;
use App\Service\Crypto\ServerCrypto;
use App\Service\Mail\MailSettingsRepository;
use App\Support\QrCode;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * The own second factor, from the user menu (docs/spec/02-sicherheit.md
 * section 3): set it up, change it, new backup codes, forget remembered
 * devices. Taken over from Vereinsbelege's SecurityController, much smaller.
 *
 * One page, Navigation::ZWEITER_FAKTOR, in three states:
 *   - nothing set up yet: choose a method - the only page the login guard
 *     lets such an account reach ("Erzwungene Einrichtung");
 *   - set up: what is in place, and to change anything, password and
 *     current code again ("Ändern nur nach erneuter Bestätigung", valid
 *     BESTAETIGT_SEKUNDEN, spent by the change);
 *   - a setup in progress (method chosen, first code missing): the code
 *     from the mail, or the QR code for the app.
 *
 * The first code proves the factor works; only then does it become the
 * account's method, and the ten backup codes are shown - once, as the
 * answer to that POST, never stored in a readable form.
 */
final readonly class ZweiterFaktorController
{
    public const string EINRICHTEN = Navigation::ZWEITER_FAKTOR . '/einrichten';
    public const string ABSCHLIESSEN = Navigation::ZWEITER_FAKTOR . '/abschliessen';
    public const string ABBRECHEN = Navigation::ZWEITER_FAKTOR . '/abbrechen';
    public const string BESTAETIGEN = Navigation::ZWEITER_FAKTOR . '/bestaetigen';
    public const string CODE_SENDEN = Navigation::ZWEITER_FAKTOR . '/code-senden';
    public const string BACKUP_CODES = Navigation::ZWEITER_FAKTOR . '/backup-codes';
    public const string GERAETE_VERGESSEN = Navigation::ZWEITER_FAKTOR . '/geraete-vergessen';

    /** Long enough to scan a QR code, short enough that an open session is no licence. */
    public const int BESTAETIGT_SEKUNDEN = 600;

    /** The name the authenticator app lists the account under. */
    public const string APP_NAME = 'Mitgliederverwaltung';

    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function seite(Request $request): Response
    {
        $benutzer = $this->benutzer();
        if ($benutzer === null) {
            return Response::redirect('/anmelden');
        }

        $einrichtung = $this->darfAendern($benutzer) ? $this->dienste->session->einrichtung() : null;
        if ($einrichtung !== null) {
            return $this->einrichtungsSeite($benutzer, $einrichtung);
        }

        return $this->uebersicht($benutzer);
    }

    /** Method chosen: send the mail code, or make a secret for the app. */
    public function einrichten(Request $request): Response
    {
        $benutzer = $this->benutzer();
        if ($benutzer === null) {
            return Response::redirect('/anmelden');
        }
        if (!$this->darfAendern($benutzer)) {
            return $this->zurueck('Bitte bestätigen Sie zuerst Passwort und Code.', FlashArt::Fehler);
        }

        $session = $this->dienste->session;
        $methode = MfaMethode::tryFrom($request->feld('methode'));
        if ($methode === MfaMethode::Email && $this->mailEingerichtet()) {
            $session->merkeEinrichtung(['methode' => MfaMethode::Email->value]);
            $this->sendeCode($benutzer);
        } elseif ($methode === MfaMethode::App) {
            $geheimnis = ZweiterFaktor::aus($this->dienste)->neuesAppGeheimnis();
            $session->merkeEinrichtung([
                'methode' => MfaMethode::App->value,
                // Ciphertext under the server key: the session file sits on
                // the webspace, the key does not.
                'geheimnis' => base64_encode(ServerCrypto::aus($this->dienste)->encrypt($geheimnis)),
            ]);
        } else {
            return $this->zurueck('Bitte wählen Sie, wie Sie sich bestätigen möchten.', FlashArt::Fehler);
        }

        return Response::redirect(Navigation::ZWEITER_FAKTOR);
    }

    /** The first code: the factor works, it becomes the account's. */
    public function abschliessen(Request $request): Response
    {
        $benutzer = $this->benutzer();
        if ($benutzer === null) {
            return Response::redirect('/anmelden');
        }
        $session = $this->dienste->session;
        $einrichtung = $this->darfAendern($benutzer) ? $session->einrichtung() : null;
        if ($einrichtung === null) {
            return Response::redirect(Navigation::ZWEITER_FAKTOR);
        }

        $faktor = ZweiterFaktor::aus($this->dienste);
        if (!$faktor->versuch($request->ip, $benutzer->id)) {
            return $this->einrichtungsSeite($benutzer, $einrichtung, 'Zu viele Versuche. Bitte warten Sie 15 Minuten und versuchen Sie es dann erneut.', 429);
        }

        $code = self::roh($request, 'code');
        $methode = MfaMethode::tryFrom($einrichtung['methode']);
        $codes = match ($methode) {
            MfaMethode::Email => $faktor->pruefeMailCode($benutzer->id, $code) ? $faktor->richteMailEin($benutzer->id) : null,
            MfaMethode::App => $faktor->richteAppEin($benutzer->id, $this->appGeheimnis($einrichtung), $code),
            null => null,
        };
        if ($codes === null || $methode === null) {
            return $this->einrichtungsSeite($benutzer, $einrichtung, 'Der Code ist ungültig oder abgelaufen.', 422);
        }
        $faktor->erfolg($benutzer->id);

        $session->merkeEinrichtung(null);
        $session->merkeBestaetigung(null);
        $this->dienste->audit()->protokollieren(AuditAktion::ZweiterFaktorGeaendert, $benutzer->id, 'user', $benutzer->id, ['methode' => $methode->value], $request->ip);
        Kontomails::aus($this->dienste)->hinweis($benutzer->email, Kontomails::ZWEITER_FAKTOR_EINGERICHTET);

        return $this->backupCodeSeite($codes, sprintf('Fertig: Ihr zweiter Faktor ist jetzt „%s“.', $methode->bezeichnung()));
    }

    public function abbrechen(Request $request): Response
    {
        $this->dienste->session->merkeEinrichtung(null);

        return Response::redirect(Navigation::ZWEITER_FAKTOR);
    }

    /** Password and current code again - before anything changes. */
    public function bestaetigen(Request $request): Response
    {
        $benutzer = $this->benutzer();
        if ($benutzer === null || $benutzer->mfaMethod === null) {
            return Response::redirect(Navigation::ZWEITER_FAKTOR);
        }

        $fehler = ZweiterFaktor::aus($this->dienste)->bestaetige($benutzer, self::roh($request, 'passwort'), self::roh($request, 'code'), $request->ip);
        if ($fehler !== null) {
            return $this->uebersicht($benutzer, $fehler, 422);
        }
        $this->dienste->session->merkeBestaetigung(new \DateTimeImmutable());

        return $this->zurueck(sprintf('Bestätigt. Sie können den zweiten Faktor jetzt %d Minuten lang ändern.', intdiv(self::BESTAETIGT_SEKUNDEN, 60)));
    }

    /**
     * A mail code - for the confirmation of an account whose factor is the
     * mail, or a new one during a mail setup. Never for an app account's
     * confirmation: the mailbox is not its factor.
     */
    public function codeSenden(Request $request): Response
    {
        $benutzer = $this->benutzer();
        $mailSetup = ($this->dienste->session->einrichtung()['methode'] ?? null) === MfaMethode::Email->value;
        if ($benutzer !== null && $this->mailEingerichtet() && ($benutzer->mfaMethod === MfaMethode::Email || $mailSetup)) {
            $this->sendeCode($benutzer);
        }

        return Response::redirect(Navigation::ZWEITER_FAKTOR);
    }

    public function backupCodes(Request $request): Response
    {
        $benutzer = $this->benutzer();
        if ($benutzer === null || $benutzer->mfaMethod === null || !$this->darfAendern($benutzer)) {
            return $this->zurueck('Bitte bestätigen Sie zuerst Passwort und Code.', FlashArt::Fehler);
        }

        $codes = ZweiterFaktor::aus($this->dienste)->neueBackupCodes($benutzer->id);
        $this->dienste->session->merkeBestaetigung(null);
        $this->dienste->audit()->protokollieren(AuditAktion::ZweiterFaktorGeaendert, $benutzer->id, 'user', $benutzer->id, ['backup_codes' => 'neu'], $request->ip);
        Kontomails::aus($this->dienste)->hinweis($benutzer->email, Kontomails::BACKUP_CODES_NEU);

        return $this->backupCodeSeite($codes, 'Ihre neuen Backup-Codes. Die bisherigen gelten nicht mehr.');
    }

    /**
     * Forgetting needs no confirmation: it only ever makes the login ask
     * more. This browser's cookie goes as well.
     */
    public function geraeteVergessen(Request $request): Response
    {
        $benutzer = $this->benutzer();
        if ($benutzer !== null) {
            ZweiterFaktor::aus($this->dienste)->vergesseGeraete($benutzer->id);
        }
        $this->dienste->session->flash('Alle gemerkten Geräte sind vergessen. Die nächste Anmeldung fragt dort wieder nach dem Code.');

        return Response::redirect(Navigation::ZWEITER_FAKTOR)->withCookie(
            Cookie::hostPrefixed(ZweiterFaktorAnmeldung::GERAET_COOKIE, '', Request::httpsFromGlobals())->expired(),
        );
    }

    /**
     * The first setup needs no confirmation - there is nothing to protect
     * yet, and the guard sends the account here anyway. Every later change
     * does.
     */
    private function darfAendern(Benutzer $benutzer): bool
    {
        return $benutzer->mfaMethod === null || $this->dienste->session->bestaetigt(self::BESTAETIGT_SEKUNDEN);
    }

    /** Whether mails can go out at all - without, only the app is offered. */
    private function mailEingerichtet(): bool
    {
        return MailSettingsRepository::aus($this->dienste)->get()->istVollstaendig();
    }

    private function sendeCode(Benutzer $benutzer): void
    {
        $session = $this->dienste->session;
        $code = ZweiterFaktor::aus($this->dienste)->neuerMailCode($benutzer->id);
        if ($code === null) {
            $session->flash('Es wurden zu viele Codes angefordert. Bitte warten Sie 15 Minuten.', FlashArt::Warnung);

            return;
        }
        try {
            $gesendet = Kontomails::aus($this->dienste)->code($benutzer->email, $code)->erfolg;
        } catch (\Throwable $e) {
            $this->dienste->logger->append(sprintf('second factor code not queued: %s', $e::class));
            $gesendet = false;
        }
        $session->flash(
            $gesendet ? 'Wir haben Ihnen einen Code per E-Mail geschickt.' : 'Der Code konnte gerade nicht verschickt werden. Bitte versuchen Sie es gleich noch einmal.',
            $gesendet ? FlashArt::Info : FlashArt::Warnung,
        );
    }

    /** @param array{methode: string, geheimnis?: string} $einrichtung */
    private function appGeheimnis(array $einrichtung): string
    {
        $gespeichert = base64_decode($einrichtung['geheimnis'] ?? '', true);
        if ($gespeichert === false || $gespeichert === '') {
            return '';
        }
        try {
            return ServerCrypto::aus($this->dienste)->decrypt($gespeichert);
        } catch (\Throwable) {
            // Another server key since the setup started: start again.
            return '';
        }
    }

    private function benutzer(): ?Benutzer
    {
        $userId = $this->dienste->session->userId();

        return $userId === null ? null : new BenutzerRepository($this->dienste->pdo())->findById($userId);
    }

    private function uebersicht(Benutzer $benutzer, ?string $fehler = null, int $status = 200): Response
    {
        $faktor = ZweiterFaktor::aus($this->dienste);

        return $this->render('konto/zweiter-faktor', $benutzer->mfaMethod === null ? 'Zweiten Faktor einrichten' : 'Zweiter Faktor', [
            'methode' => $benutzer->mfaMethod,
            'darfAendern' => $this->darfAendern($benutzer),
            'mailEingerichtet' => $this->mailEingerichtet(),
            'email' => $benutzer->email,
            'backupCodes' => $benutzer->mfaMethod === null ? 0 : $faktor->offeneBackupCodes($benutzer->id),
            'geraete' => $benutzer->mfaMethod === null ? 0 : $faktor->gemerkteGeraete($benutzer->id),
            'fehler' => $fehler,
        ], $status);
    }

    /** @param array{methode: string, geheimnis?: string} $einrichtung */
    private function einrichtungsSeite(Benutzer $benutzer, array $einrichtung, ?string $fehler = null, int $status = 200): Response
    {
        $methode = MfaMethode::tryFrom($einrichtung['methode']) ?? MfaMethode::App;
        $daten = ['methode' => $methode, 'fehler' => $fehler, 'email' => $benutzer->email];
        if ($methode === MfaMethode::App) {
            $geheimnis = $this->appGeheimnis($einrichtung);
            if ($geheimnis === '') {
                $this->dienste->session->merkeEinrichtung(null);

                return $this->uebersicht($benutzer);
            }
            $daten['qrSvg'] = QrCode::svg(Totp::uri($geheimnis, $benutzer->email, self::APP_NAME), label: 'QR-Code für die Authenticator-App');
            $daten['schluessel'] = trim(chunk_split(rtrim(Totp::base32Encode($geheimnis), '='), 4, ' '));
        }

        return $this->render('konto/zweiter-faktor-einrichten', 'Zweiten Faktor einrichten', $daten, $status);
    }

    /** @param list<string> $codes */
    private function backupCodeSeite(array $codes, string $meldung): Response
    {
        return $this->render('konto/backup-codes', 'Backup-Codes', [
            'codes' => $codes,
            'meldung' => $meldung,
            'scripts' => ['/js/drucken.js'],
        ]);
    }

    /** @param array<string, mixed> $daten */
    private function render(string $template, string $titel, array $daten, int $status = 200): Response
    {
        return Response::html($this->dienste->view->render($template, ['title' => $titel, ...$daten], Area::App), $status);
    }

    private function zurueck(string $meldung, FlashArt $art = FlashArt::Ok): Response
    {
        $this->dienste->session->flash($meldung, $art);

        return Response::redirect(Navigation::ZWEITER_FAKTOR);
    }

    private static function roh(Request $request, string $feld): string
    {
        $wert = $request->post[$feld] ?? '';

        return is_string($wert) ? $wert : '';
    }
}
