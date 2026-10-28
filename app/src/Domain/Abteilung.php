<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A department (docs/spec/07-mitglieder.md section 2). Deleting is only
 * possible without members, otherwise it is deactivated
 * (App\Repository\AbteilungRepository::loeschen()).
 */
final readonly class Abteilung
{
    use Kopierbar;

    public function __construct(
        public string $name,
        /** BLSV "Spartenkennzeichen", text - the leading zero matters ("09"). */
        public string $blsvNummer = '',
        public int $sortierung = 0,
        public bool $aktiv = true,
        /** Null until saved. */
        public ?int $id = null,
    ) {
    }
}
