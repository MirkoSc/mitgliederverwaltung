<?php

declare(strict_types=1);

namespace App\Service\Karte;

use App\Domain\Mitglied;
use App\Repository\GeoAnschriftRepository;

/**
 * Where one member lives as the map sees it (issue #106): the block
 * "Wohnort auf der Karte" in the tab "Daten". Built on every view from the
 * member's address and its row in geo_anschrift - nothing of its own is
 * stored.
 */
final readonly class Wohnort
{
    public function __construct(
        public Anschrift $anschrift,
        public WohnortZustand $zustand,
        public ?float $lat = null,
        public ?float $lon = null,
        public ?Anschrift $korrektur = null,
    ) {
    }

    public static function aus(Mitglied $mitglied, GeoAnschriftRepository $koordinaten, \DateTimeImmutable $heute): self
    {
        $anschrift = self::anschrift($mitglied);
        if ($mitglied->istEhemalig($heute)) {
            return new self($anschrift, WohnortZustand::Ehemalig);
        }
        if ($anschrift->istLeer()) {
            return new self($anschrift, WohnortZustand::OhneAnschrift);
        }

        $eintrag = $koordinaten->eintrag($anschrift->hash());
        $korrektur = $eintrag['korrektur'] ?? null;
        $korrektur = $korrektur === null ? null : new Anschrift($korrektur['strasse'], $korrektur['plz'], $korrektur['ort'], $anschrift->land);
        $lat = $eintrag['lat'] ?? null;
        $lon = $eintrag['lon'] ?? null;

        $zustand = match (true) {
            ($eintrag['status'] ?? null) === GeoStatus::Gefunden && $lat !== null && $lon !== null => WohnortZustand::Gefunden,
            ($eintrag['status'] ?? null) === GeoStatus::NichtGefunden => WohnortZustand::NichtGefunden,
            default => WohnortZustand::Offen,
        };

        return new self($anschrift, $zustand, $zustand === WohnortZustand::Gefunden ? $lat : null, $zustand === WohnortZustand::Gefunden ? $lon : null, $korrektur);
    }

    /** The member's address as the map sees it. */
    public static function anschrift(Mitglied $mitglied): Anschrift
    {
        return new Anschrift($mitglied->strasse, $mitglied->plz, $mitglied->ort, $mitglied->land);
    }

    /** Whether a search address can be given or the search started here at all. */
    public function suchbar(): bool
    {
        return $this->zustand !== WohnortZustand::Ehemalig && $this->zustand !== WohnortZustand::OhneAnschrift;
    }

    /**
     * What the form "Anders suchen" shows: the search address given so
     * far, else the member's address with the town up to its first minus -
     * the part the geocoder gets anyway.
     */
    public function vorbelegung(): Anschrift
    {
        return $this->korrektur
            ?? new Anschrift($this->anschrift->strasse, $this->anschrift->plz, $this->anschrift->ortFuerSuche(), $this->anschrift->land);
    }
}
