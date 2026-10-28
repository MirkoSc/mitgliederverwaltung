<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;

/**
 * One unit of work the cron endpoint runs (docs/spec/01-plattform.md section 5).
 *
 * An issue adds its task as `app/src/Service/Cron/<Name>Task.php`; the cron
 * finds it by file name (App\Service\Cron\CronAufgaben) - no central list.
 *
 * A task touches no member data and needs no logged-in user: mail dispatch
 * and housekeeping are the only things that belong here. It must be safe to
 * run again at any time - a run may be cut off by the host, and the next one
 * starts over.
 */
interface CronTask
{
    /** Built per run; take what is needed from the services. */
    public static function erzeugen(Dienste $dienste): self;

    /** Stable identifier shown in the run result and the log. */
    public function name(): string;

    /**
     * Housekeeping runs at most once per interval, everything else (mail
     * queue) on every call.
     */
    public function istAufraeumen(): bool;

    /**
     * @return int how many units were handled (rows removed, mails sent, ...)
     */
    public function run(\DateTimeImmutable $now): int;
}
