<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Http\Request;
use App\Http\Response;
use App\Service\Mail\MailSettings;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\Mailer;
use App\Service\Mail\SmtpSecurity;
use App\View\FlashArt;

/**
 * The two forms of the settings section Mail: save and send a test mail.
 * Both answer with a redirect back to the section and a flash message
 * (post/redirect/get); the message never contains the address or the
 * password.
 */
final readonly class MailController
{
    private const string ZIEL = '/admin#mail';

    public function __construct(
        private Dienste $dienste,
        private MailSettingsRepository $einstellungen,
        private Mailer $mailer,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste, MailSettingsRepository::aus($dienste), Mailer::aus($dienste));
    }

    public function speichern(Request $request): Response
    {
        $host = $request->feld('host');
        $port = (int) $request->feld('port');
        $sicherheit = SmtpSecurity::tryFrom($request->feld('sicherheit'));
        $absender = $request->feld('absender');
        $antwortAn = $request->feld('antwort_an');
        $url = $request->feld('oeffentliche_url');

        $fehler = MailSettings::eingabeFehler($host, $port, $sicherheit, $absender, $antwortAn, $url);
        if ($fehler !== null || $sicherheit === null) {
            $this->dienste->session->flash($fehler ?? 'Bitte eine Verschlüsselung auswählen.', FlashArt::Fehler);

            return Response::redirect(self::ZIEL);
        }

        // The password is not trimmed (feld() would): spaces may belong to it.
        $passwort = $request->post['passwort'] ?? '';
        $passwort = is_string($passwort) ? $passwort : '';
        $this->einstellungen->speichern(
            host: $host,
            port: $port,
            sicherheit: $sicherheit,
            benutzer: $request->feld('benutzer'),
            // An empty field keeps the stored password; "remove" is its own tick box.
            neuesPasswort: $request->feld('passwort_entfernen') === '1' ? '' : ($passwort === '' ? null : $passwort),
            absender: $absender,
            antwortAn: $antwortAn,
            vereinsname: $request->feld('vereinsname'),
            oeffentlicheUrl: $url,
        );
        $this->dienste->session->flash('Die Mail-Einstellungen sind gespeichert.');

        return Response::redirect(self::ZIEL);
    }

    public function testmail(Request $request): Response
    {
        $empfaenger = $request->feld('empfaenger');
        if (filter_var($empfaenger, FILTER_VALIDATE_EMAIL) === false) {
            $this->dienste->session->flash('Bitte eine gültige Empfängeradresse für die Testmail angeben.', FlashArt::Fehler);

            return Response::redirect(self::ZIEL);
        }

        $ergebnis = $this->mailer->sendeTestmail($empfaenger);
        if ($ergebnis->erfolg) {
            $this->dienste->session->flash('Die Testmail wurde versendet. Bitte den Eingang prüfen.');
        } else {
            $this->dienste->session->flash(
                'Die Testmail konnte nicht versendet werden: ' . ($ergebnis->fehlermeldung ?? 'unbekannter Fehler'),
                FlashArt::Fehler,
            );
        }

        return Response::redirect(self::ZIEL);
    }
}
