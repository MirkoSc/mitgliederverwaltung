<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * What the geocoder said about one address (geo_anschrift.status). The
 * values are stored and part of the data format.
 */
enum GeoStatus: string
{
    case Gefunden = 'gefunden';

    /** Remembered, so the address is not asked again until it changes. */
    case NichtGefunden = 'nicht_gefunden';
}
