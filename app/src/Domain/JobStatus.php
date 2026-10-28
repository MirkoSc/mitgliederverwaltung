<?php

declare(strict_types=1);

namespace App\Domain;

/** States of a step chain (`job.status`, docs/spec/01-plattform.md section 4). */
enum JobStatus: string
{
    case Offen = 'offen';
    case Laeuft = 'laeuft';
    case Fertig = 'fertig';
    case Fehler = 'fehler';
}
