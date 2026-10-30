<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Dienste;
use App\Service\Karte\Koordinatenermittlung;

/**
 * Looks up the coordinates of a few member addresses per run for the page
 * "Karte" (issue #91, docs/spec/01-plattform.md section 5). Runs on every
 * call, like the mail queue: with a cron every ten minutes, a club's
 * addresses are through within a day, and the admin's button
 * "Jetzt Koordinaten ermitteln" is there for the impatient.
 *
 * The one task that reads member data: the address goes to the geocoder,
 * nothing of it into the result or the log.
 */
final readonly class KoordinatenTask implements CronTask
{
    /** Addresses per run - with one request per second this is ~11 s. */
    public const int JE_LAUF = 10;

    /** Seconds after which a run asks nobody more, well below the 30 s limit. */
    public const float ZEITBUDGET_S = 12.0;

    public function __construct(private Koordinatenermittlung $ermittlung)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(Koordinatenermittlung::aus($dienste));
    }

    public function name(): string
    {
        return 'koordinaten';
    }

    public function istAufraeumen(): bool
    {
        return false;
    }

    public function run(\DateTimeImmutable $now): int
    {
        return $this->ermittlung->ermitteln($now, self::JE_LAUF, self::ZEITBUDGET_S)->bearbeitet;
    }
}
