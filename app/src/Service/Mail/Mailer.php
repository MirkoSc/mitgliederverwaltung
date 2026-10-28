<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Dienste;
use App\Domain\MailStatus;
use App\Domain\QueuedMail;
use App\Repository\MailQueueRepository;

/**
 * Facade used by the settings section (the test mail), by the areas that
 * send mails (login, invitations) and by the cron task (queued retries) -
 * the same class runs inside a request and inside the cron.
 *
 * Two ways in (docs/spec/01-plattform.md section 6):
 *  - sendeSofort(): security mails (reset, 2FA code, new device) are queued
 *    and attempted at once, so the page can say right away whether it went
 *    out; a failure stays in the queue and the cron retries it;
 *  - einreihen(): everything else is only queued, the cron sends it.
 *
 * Mails go to users only and carry no member data (CLAUDE.md section 4).
 */
final readonly class Mailer
{
    /** After the 5th failed attempt a mail stops retrying. */
    public const int MAX_ATTEMPTS = 5;

    /** Backoff in seconds; index 0 applies after the 1st failure. */
    private const array BACKOFF_SECONDS = [60, 300, 900, 3600, 10800];

    public function __construct(
        private MailQueueRepository $queue,
        private MailSettingsRepository $einstellungen,
        private MailTemplates $vorlagen,
        private MailTransport $transport = new SmtpTransport(),
    ) {
    }

    /**
     * The mailer of this request. Tests put a fake transport in with
     * $dienste->dienst(MailTransport::class, …) before the first call.
     */
    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            new MailQueueRepository($d->pdo()),
            MailSettingsRepository::aus($d),
            new MailTemplates($d->paths->viewsDir() . '/mail'),
            $d->dienst(MailTransport::class, static fn(): MailTransport => new SmtpTransport()),
        ));
    }

    /** Renders a template below app/views/mail/ - a mail body, plain text. */
    public function vorlage(string $name, array $daten = []): string
    {
        return $this->vorlagen->render($name, $daten + ['vereinsname' => $this->vereinsname()]);
    }

    /** Only queued; the cron sends it with its next run. */
    public function einreihen(string $empfaenger, string $betreff, string $text, ?\DateTimeImmutable $now = null): int
    {
        return $this->queue->enqueue($empfaenger, $betreff, $text, $now ?? new \DateTimeImmutable());
    }

    /** Queued and attempted at once (see class docblock). */
    public function sendeSofort(string $empfaenger, string $betreff, string $text, ?\DateTimeImmutable $now = null): MailAttemptResult
    {
        $now ??= new \DateTimeImmutable();
        $id = $this->queue->enqueue($empfaenger, $betreff, $text, $now);
        $mail = $this->queue->claimById($id, $now);
        if ($mail === null) {
            // Cannot happen for a row nobody else touched yet, but every
            // claim can in principle miss.
            return new MailAttemptResult(false, 'Die Mail konnte nicht aus der Warteschlange geholt werden.');
        }

        return $this->versuch($mail, $this->einstellungen->get(), $now);
    }

    /** The purely technical test mail of the settings section. */
    public function sendeTestmail(string $empfaenger, ?\DateTimeImmutable $now = null): MailAttemptResult
    {
        $now ??= new \DateTimeImmutable();

        return $this->sendeSofort(
            $empfaenger,
            'Testmail der Mitgliederverwaltung',
            $this->vorlage('testmail', ['zeit' => $now]),
            $now,
        );
    }

    /** Cron entry point (App\Service\Cron\MailQueueTask): everything due, one attempt each. */
    public function sendeFaellige(\DateTimeImmutable $now, int $limit = 10): int
    {
        $einstellungen = $this->einstellungen->get();
        $erledigt = 0;
        foreach ($this->queue->claimDue($now, $limit) as $mail) {
            if ($this->versuch($mail, $einstellungen, $now)->erfolg) {
                $erledigt++;
            }
        }

        return $erledigt;
    }

    private function vereinsname(): string
    {
        $name = $this->einstellungen->get()->vereinsname;

        return $name !== '' ? $name : 'dem Verein';
    }

    private function versuch(QueuedMail $mail, MailSettings $einstellungen, \DateTimeImmutable $now): MailAttemptResult
    {
        try {
            if (!$einstellungen->istVollstaendig()) {
                throw new MailException('Die Mail-Einstellungen sind unvollständig.');
            }

            $this->transport->send(new MailMessage($mail->to, $mail->subject, $mail->body), $einstellungen);
            $this->queue->markSent($mail->id, $now);

            return new MailAttemptResult(true);
        } catch (\Throwable $e) {
            $this->aufgebenOderWiederholen($mail, $e::class, $now);

            // MailException messages are safe to show by construction;
            // anything else is reported by class name only (CLAUDE.md section 4).
            return new MailAttemptResult(false, $e instanceof MailException ? $e->getMessage() : $e::class);
        }
    }

    private function aufgebenOderWiederholen(QueuedMail $mail, string $fehlerKlasse, \DateTimeImmutable $now): void
    {
        $endgueltig = $mail->attempts >= self::MAX_ATTEMPTS;
        $wartesekunden = self::BACKOFF_SECONDS[min($mail->attempts, count(self::BACKOFF_SECONDS)) - 1];

        $this->queue->markFailed(
            $mail->id,
            $fehlerKlasse,
            $endgueltig ? MailStatus::Fehler : MailStatus::Offen,
            $now->setTimestamp($now->getTimestamp() + $wartesekunden),
            $now,
        );
    }
}
