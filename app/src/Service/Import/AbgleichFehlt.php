<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Mitglied;

/**
 * A member of the section "Fehlt in der Datei" (docs/spec/10-import.md
 * section 4): imported once, missing in the later file. Either it has not
 * left - then it may only leave - or it is former (leaving date reached)
 * and has no annual fees - then it may be deleted (07 section 1: deleting
 * is for former members only).
 */
final readonly class AbgleichFehlt
{
    public function __construct(
        public Mitglied $mitglied,
        /** True for a former member without annual fees: it may be deleted, it cannot leave again. */
        public bool $kannLoeschen,
    ) {
    }
}
