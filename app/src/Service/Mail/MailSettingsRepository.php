<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Dienste;
use App\Repository\SettingRepository;

/**
 * Reads and writes the mail settings through the `setting` table
 * (docs/spec/01-plattform.md section 6). The password only ever reaches the
 * table as ciphertext from App\Service\Mail\GeheimnisSchutz.
 */
final readonly class MailSettingsRepository
{
    private const string HOST = 'mail_smtp_host';

    private const string PORT = 'mail_smtp_port';

    private const string SICHERHEIT = 'mail_smtp_sicherheit';

    private const string BENUTZER = 'mail_smtp_benutzer';

    /** Also rewrapped by the key change (App\Repository\SchluesselwechselRepository). */
    public const string PASSWORT = 'mail_smtp_passwort_enc';

    private const string ABSENDER = 'mail_absender';

    private const string ANTWORT_AN = 'mail_antwort_an';

    private const string VEREINSNAME = 'mail_vereinsname';

    private const string OEFFENTLICHE_URL = 'oeffentliche_url';

    public function __construct(
        private SettingRepository $settings,
        private GeheimnisSchutz $schutz,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->einstellungen(),
            $d->dienst(GeheimnisSchutz::class, static fn(Dienste $d): GeheimnisSchutz => new ServerKeyGeheimnisSchutz($d->config->serverKey, $d->config->serverKeyAlt)),
        ));
    }

    public function get(): MailSettings
    {
        $geschuetzt = $this->settings->text(self::PASSWORT);
        $passwort = '';
        if ($geschuetzt !== '') {
            try {
                $passwort = $this->schutz->oeffnen($geschuetzt);
            } catch (MailException) {
                // Another server key or a damaged row must not crash the
                // settings page: it shows up as "not set" and the admin
                // enters the password again.
                $geschuetzt = '';
            }
        }

        return new MailSettings(
            host: $this->settings->text(self::HOST),
            port: $this->settings->zahl(self::PORT, 587),
            sicherheit: SmtpSecurity::tryFrom($this->settings->text(self::SICHERHEIT)) ?? SmtpSecurity::Starttls,
            benutzer: $this->settings->text(self::BENUTZER),
            passwort: $passwort,
            passwortGesetzt: $geschuetzt !== '',
            absender: $this->settings->text(self::ABSENDER),
            antwortAn: $this->settings->text(self::ANTWORT_AN),
            vereinsname: $this->settings->text(self::VEREINSNAME),
            oeffentlicheUrl: $this->settings->text(self::OEFFENTLICHE_URL),
        );
    }

    /**
     * @param ?string $neuesPasswort null keeps the stored password, '' clears
     *        it, anything else replaces it. The form never carries the
     *        stored password back, so there is no other way to say "leave
     *        it as is".
     */
    public function speichern(
        string $host,
        int $port,
        SmtpSecurity $sicherheit,
        string $benutzer,
        #[\SensitiveParameter] ?string $neuesPasswort,
        string $absender,
        string $antwortAn,
        string $vereinsname,
        string $oeffentlicheUrl,
    ): void {
        $this->settings->set(self::HOST, $host);
        $this->settings->set(self::PORT, $port);
        $this->settings->set(self::SICHERHEIT, $sicherheit->value);
        $this->settings->set(self::BENUTZER, $benutzer);
        if ($neuesPasswort !== null) {
            $this->settings->set(self::PASSWORT, $neuesPasswort === '' ? '' : $this->schutz->schuetzen($neuesPasswort));
        }
        $this->settings->set(self::ABSENDER, $absender);
        $this->settings->set(self::ANTWORT_AN, $antwortAn);
        $this->settings->set(self::VEREINSNAME, $vereinsname);
        $this->settings->set(self::OEFFENTLICHE_URL, PublicUrl::normalize($oeffentlicheUrl) ?? '');
    }
}
