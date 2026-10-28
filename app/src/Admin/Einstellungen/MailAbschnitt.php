<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Repository\MailQueueRepository;
use App\Service\Mail\MailSettingsRepository;
use App\Service\Mail\SmtpSecurity;

/**
 * Settings section Mail (docs/spec/01-plattform.md section 6): SMTP server,
 * sender, public address of the installation, test mail. The forms post to
 * app/routes/mail.php.
 */
final readonly class MailAbschnitt implements Abschnitt
{
    public function __construct(
        private MailSettingsRepository $einstellungen,
        private MailQueueRepository $queue,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(MailSettingsRepository::aus($dienste), new MailQueueRepository($dienste->pdo()));
    }

    public function schluessel(): string
    {
        return 'mail';
    }

    public function titel(): string
    {
        return 'Mail';
    }

    public function reihenfolge(): int
    {
        return 80;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'admin/einstellungen/mail';
    }

    public function daten(): array
    {
        return [
            'einstellungen' => $this->einstellungen->get(),
            'sicherheiten' => SmtpSecurity::cases(),
            'fehlgeschlagen' => $this->queue->anzahlFehler(),
        ];
    }
}
