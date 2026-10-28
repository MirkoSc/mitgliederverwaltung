<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * SMTP / sender settings (docs/spec/01-plattform.md section 6). Read and
 * written through App\Service\Mail\MailSettingsRepository - the password in
 * the `setting` table is ciphertext.
 */
final readonly class MailSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public SmtpSecurity $sicherheit,
        public string $benutzer,
        /** Decrypted password. Never rendered back into a form. */
        public string $passwort,
        public bool $passwortGesetzt,
        public string $absender,
        public string $antwortAn,
        public string $vereinsname,
        /**
         * Public address of this installation, e.g. https://mitglieder.verein.de
         * - the base of every link a mail carries (App\Service\Mail\PublicUrl).
         */
        public string $oeffentlicheUrl = '',
    ) {
    }

    /** What is wrong with a submitted form, or null. */
    public static function eingabeFehler(
        string $host,
        int $port,
        ?SmtpSecurity $sicherheit,
        string $absender,
        string $antwortAn,
        string $oeffentlicheUrl,
    ): ?string {
        if (filter_var($absender, FILTER_VALIDATE_EMAIL) === false) {
            return 'Bitte eine gültige Absenderadresse angeben.';
        }
        if ($antwortAn !== '' && filter_var($antwortAn, FILTER_VALIDATE_EMAIL) === false) {
            return 'Die Antwort-an-Adresse ist keine gültige E-Mail-Adresse.';
        }
        if ($host === '') {
            return 'Bitte einen SMTP-Server angeben.';
        }
        if ($port < 1 || $port > 65535) {
            return 'Der Port muss zwischen 1 und 65535 liegen.';
        }
        if ($sicherheit === null) {
            return 'Bitte eine Verschlüsselung auswählen.';
        }
        if ($oeffentlicheUrl !== '' && PublicUrl::normalize($oeffentlicheUrl) === null) {
            return 'Die öffentliche Adresse muss mit https:// beginnen und darf nur Server und Port enthalten.';
        }

        return null;
    }

    /** Whether sending can be attempted at all - checked before every attempt. */
    public function istVollstaendig(): bool
    {
        return $this->absender !== '' && $this->host !== '';
    }

    /**
     * Keeps the password out of var_dump() output, like Config::__debugInfo().
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => (string) $this->port,
            'sicherheit' => $this->sicherheit->value,
            'benutzer' => $this->benutzer,
            'passwort' => $this->passwortGesetzt ? '*** gesetzt ***' : '(nicht gesetzt)',
            'absender' => $this->absender,
            'antwortAn' => $this->antwortAn,
            'vereinsname' => $this->vereinsname,
            'oeffentlicheUrl' => $this->oeffentlicheUrl,
        ];
    }
}
