<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * The outcome of one search for one address on demand (issue #106): the
 * button "Jetzt suchen", saving or removing a search address at a member
 * (Koordinatenermittlung::jetztSuchen(), korrigieren(), korrekturEntfernen()).
 */
enum HandSuche
{
    case Gefunden;

    case NichtGefunden;

    /** The cron or the admin's button is asking right now - nothing changed, try again. */
    case Belegt;

    /** The geocoding rests after a failure of the service; a search address is kept and asked later. */
    case Ruht;

    /** The service failed just now; a search address is kept and asked later. */
    case Fehler;
}
