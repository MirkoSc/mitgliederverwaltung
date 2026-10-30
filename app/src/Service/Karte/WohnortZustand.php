<?php

declare(strict_types=1);

namespace App\Service\Karte;

/** The state of the block "Wohnort auf der Karte" at a member (issue #106). */
enum WohnortZustand
{
    case Gefunden;

    case NichtGefunden;

    /** Not asked yet - the cron gets to it, or "Jetzt suchen". */
    case Offen;

    /** Neither postcode nor town: nothing to look up. */
    case OhneAnschrift;

    /** A former member is not on the map (issue #91). */
    case Ehemalig;
}
