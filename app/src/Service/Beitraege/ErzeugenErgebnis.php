<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

/**
 * What "Beiträge für <Jahr> erzeugen" did: how many fees are new and how
 * many members already had theirs.
 */
final readonly class ErzeugenErgebnis
{
    public function __construct(
        public int $jahr,
        public int $neu,
        public int $vorhanden,
    ) {
    }

    /** The message after pressing the button, German. */
    public function meldung(): string
    {
        $neu = match ($this->neu) {
            0 => sprintf('Für %d gab es keine fehlenden Jahresbeiträge.', $this->jahr),
            1 => sprintf('1 Jahresbeitrag für %d erzeugt.', $this->jahr),
            default => sprintf('%d Jahresbeiträge für %d erzeugt.', $this->neu, $this->jahr),
        };
        if ($this->vorhanden === 0) {
            return $neu;
        }

        return $neu . ' ' . ($this->vorhanden === 1
            ? '1 Mitglied hatte seinen schon.'
            : sprintf('%d Mitglieder hatten ihren schon.', $this->vorhanden));
    }
}
