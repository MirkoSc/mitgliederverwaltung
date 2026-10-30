<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * Clock and pause of the geocoder's rate limit (at most one request per
 * second, Nominatim usage policy). A fake in the tests: they check the
 * pauses without waiting for them.
 */
interface Takt
{
    /** Seconds since the epoch, with fraction. */
    public function jetzt(): float;

    public function warten(float $sekunden): void;
}
