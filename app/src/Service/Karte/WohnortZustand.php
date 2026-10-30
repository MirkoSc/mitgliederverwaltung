<?php

declare(strict_types=1);

namespace App\Service\Karte;

/** The state of the block "Wohnort auf der Karte" at a member (issues #106, #108). */
enum WohnortZustand
{
    case Gefunden;

    /** Only postcode and town were found: the dot stands in the middle of the place (issue #108). */
    case Ungefaehr;

    /** The point was set by hand (issue #108). */
    case VonHand;

    case NichtGefunden;

    /** Not asked yet - the cron gets to it, or "Jetzt suchen". */
    case Offen;

    /** Neither postcode nor town: nothing to look up. */
    case OhneAnschrift;

    /** A former member is not on the map (issue #91). */
    case Ehemalig;

    public static function aus(?GeoStatus $status): self
    {
        return match ($status) {
            GeoStatus::Gefunden => self::Gefunden,
            GeoStatus::Ungefaehr => self::Ungefaehr,
            GeoStatus::VonHand => self::VonHand,
            GeoStatus::NichtGefunden => self::NichtGefunden,
            null => self::Offen,
        };
    }

    /** Whether the address has a dot on the map. */
    public function hatPunkt(): bool
    {
        return in_array($this, [self::Gefunden, self::Ungefaehr, self::VonHand], true);
    }
}
