<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * The outcome of one search for one address on demand (issue #106): the
 * button "Jetzt suchen", saving or removing a search address at a member,
 * removing a point set by hand (issue #108) - Koordinatenermittlung::
 * jetztSuchen(), korrigieren(), korrekturEntfernen(), punktEntfernen().
 */
enum HandSuche
{
    case Gefunden;

    /** Only postcode and town were found (issue #108). */
    case Ungefaehr;

    case NichtGefunden;

    /** The cron or the admin's button is asking right now - nothing changed, try again. */
    case Belegt;

    /** The geocoding rests after a failure of the service; a search address is kept and asked later. */
    case Ruht;

    /** The service failed just now; a search address is kept and asked later. */
    case Fehler;

    /**
     * A point set by hand stands at the address (issue #108): no search
     * replaces it - nothing changed. Remove the point first.
     */
    case PunktVonHand;

    public static function aus(GeoStatus $status): self
    {
        return match ($status) {
            GeoStatus::Gefunden => self::Gefunden,
            GeoStatus::Ungefaehr => self::Ungefaehr,
            GeoStatus::NichtGefunden => self::NichtGefunden,
            GeoStatus::VonHand => self::PunktVonHand,
        };
    }
}
