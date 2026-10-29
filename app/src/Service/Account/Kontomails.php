<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dienste;
use App\Http\Request;
use App\PublicPages\AnmeldungController;
use App\Service\Mail\MailAttemptResult;
use App\Service\Mail\Mailer;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\PublicUrl;
use App\Support\FileLogger;

/**
 * The mails of the account lifecycle (docs/spec/02-sicherheit.md
 * section 3): invitation link, reset link, the code of the second factor
 * and the security notices "Passwort geändert/zurückgesetzt", "neues
 * Gerät", "2FA geändert". Taken over from Vereinsbelege's Mailer
 * methods of the same purpose.
 *
 * All of them are attempted at once (App\Service\Mail\Mailer::sendeSofort())
 * - a failed attempt stays in the queue and the cron retries it. They carry
 * no member data (CLAUDE.md section 4), and a link is only ever built on a
 * trustworthy base (App\Service\Mail\PublicUrl): without one there is no
 * mail, never a link to whatever host the request named.
 */
final readonly class Kontomails
{
    /** The fixed sentences of the security notice - never user input. */
    public const string PASSWORT_GEAENDERT = 'Das Passwort Ihres Kontos wurde geändert.';

    public const string PASSWORT_ZURUECKGESETZT = 'Das Passwort Ihres Kontos wurde über „Passwort vergessen“ neu festgelegt.';

    public const string ZWEITER_FAKTOR_EINGERICHTET = 'Für Ihr Konto wurde der zweite Faktor eingerichtet oder geändert.';

    public const string BACKUP_CODES_NEU = 'Für Ihr Konto wurden neue Backup-Codes erzeugt. Die bisherigen gelten nicht mehr.';

    public const string NEUES_GERAET = 'Ein Gerät wurde gemerkt: Dort fragt die Anmeldung 30 Tage lang nicht nach dem zweiten Faktor.';

    public function __construct(
        private Mailer $mailer,
        private MailSettingsRepository $einstellungen,
        private FileLogger $logger,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            Mailer::aus($d),
            MailSettingsRepository::aus($d),
            $d->logger,
        ));
    }

    /**
     * Base of the links in mails for this request, or null when there is no
     * trustworthy one.
     */
    public function basis(Request $request): ?string
    {
        return PublicUrl::resolve($this->einstellungen->get(), $request->header('host') ?? '', Request::httpsFromGlobals());
    }

    /**
     * @return MailAttemptResult|null null = not sent, no trustworthy base URL
     */
    public function einladung(Request $request, string $email, #[\SensitiveParameter] string $token): ?MailAttemptResult
    {
        $basis = $this->basis($request);
        if ($basis === null) {
            $this->ohneBasis('invitation');

            return null;
        }

        return $this->mailer->sendeSofort($email, 'Einladung zur Mitgliederverwaltung', $this->mailer->vorlage('einladung', [
            'link' => $basis . AnmeldungController::EINLADUNG . '?token=' . $token,
            'gueltigStunden' => intdiv(Einladung::GUELTIG_SEKUNDEN, 3600),
        ]));
    }

    /**
     * @return MailAttemptResult|null null = not sent, no trustworthy base URL
     */
    public function resetLink(Request $request, string $email, #[\SensitiveParameter] string $token): ?MailAttemptResult
    {
        $basis = $this->basis($request);
        if ($basis === null) {
            $this->ohneBasis('password reset');

            return null;
        }

        return $this->mailer->sendeSofort($email, 'Neues Passwort für die Mitgliederverwaltung', $this->mailer->vorlage('passwort-reset', [
            'link' => $basis . AnmeldungController::PASSWORT_NEU . '?token=' . $token,
            'gueltigMinuten' => intdiv(PasswortReset::GUELTIG_SEKUNDEN, 60),
        ]));
    }

    /**
     * The code of the second factor (02 section 3): at the login and when
     * the mail is set up as the factor. No link, so no base URL is needed.
     */
    public function code(string $email, #[\SensitiveParameter] string $code): MailAttemptResult
    {
        return $this->mailer->sendeSofort($email, 'Ihr Anmeldecode für die Mitgliederverwaltung', $this->mailer->vorlage('anmeldecode', [
            'code' => $code,
            'gueltigMinuten' => intdiv(ZweiterFaktor::CODE_GUELTIG_SEKUNDEN, 60),
        ]));
    }

    /**
     * A security notice to the account itself. Best effort: the change it
     * reports has already happened, a mail problem must not undo or hide
     * it.
     */
    public function hinweis(string $email, string $ereignis): void
    {
        try {
            $this->mailer->sendeSofort($email, 'Sicherheitshinweis zu Ihrem Konto', $this->mailer->vorlage('sicherheitshinweis', [
                'ereignis' => $ereignis,
            ]));
        } catch (\Throwable $e) {
            $this->logger->append(sprintf('security notice not queued: %s', $e::class));
        }
    }

    private function ohneBasis(string $was): void
    {
        // The reason, never the address or the token.
        $this->logger->append(sprintf('%s: no public URL configured and the request host does not match the sender domain - no mail sent', $was));
    }
}
