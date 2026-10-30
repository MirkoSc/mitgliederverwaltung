<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * What the map knows about one address (geo_anschrift.status). The values
 * are stored and part of the data format.
 */
enum GeoStatus: string
{
    /** The geocoder found the street - with the right postcode (issue #108). */
    case Gefunden = 'gefunden';

    /**
     * The street was not found, but postcode and town were (issue #108):
     * the dot stands in the middle of the place, not at the house.
     */
    case Ungefaehr = 'ungefaehr';

    /** Remembered, so the address is not asked again until it changes. */
    case NichtGefunden = 'nicht_gefunden';

    /**
     * Set by hand on the small map at a member (issue #108). Comes before
     * every automatic search and is never overwritten by one.
     */
    case VonHand = 'von_hand';

    /** Whether the map has a dot for this status. */
    public function hatPunkt(): bool
    {
        return $this !== self::NichtGefunden;
    }

    /** What the automatic search wrote - forgotten by "Alle neu ermitteln". */
    public function istAutomatisch(): bool
    {
        return $this !== self::VonHand;
    }

    /** The label on the page ("gefunden", "ungefähr", ...). */
    public function etikett(): string
    {
        return match ($this) {
            self::Gefunden => 'gefunden',
            self::Ungefaehr => 'ungefähr',
            self::NichtGefunden => 'nicht gefunden',
            self::VonHand => 'von Hand',
        };
    }

    /** @return list<string> the stored values of the automatic results */
    public static function automatischeWerte(): array
    {
        return array_values(array_map(
            static fn(self $s): string => $s->value,
            array_filter(self::cases(), static fn(self $s): bool => $s->istAutomatisch()),
        ));
    }
}
