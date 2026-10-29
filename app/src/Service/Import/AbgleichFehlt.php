<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Mitglied;

/**
 * A member of the section "Fehlt in der Datei" (docs/spec/10-import.md
 * section 4): imported once, missing in the later file, not yet left.
 */
final readonly class AbgleichFehlt
{
    public function __construct(
        public Mitglied $mitglied,
        /** False when the member has annual fees: then it may only leave, never be deleted. */
        public bool $kannLoeschen,
    ) {
    }
}
