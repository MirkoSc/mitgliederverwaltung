<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Service\Mail\Mailer;

/**
 * Sends everything due in the mail queue (docs/spec/01-plattform.md section
 * 6). Runs on every call, not as housekeeping: a retry must not wait for the
 * next cleanup window.
 */
final readonly class MailQueueTask implements CronTask
{
    /** How many mails one cron call sends at most - a call must stay short (CLAUDE.md section 1). */
    private const int BATCH = 10;

    public function __construct(private Mailer $mailer)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(Mailer::aus($dienste));
    }

    public function name(): string
    {
        return 'mail_versenden';
    }

    public function istAufraeumen(): bool
    {
        return false;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->mailer->sendeFaellige($now, self::BATCH);
    }
}
