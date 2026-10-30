<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Karte\Takt;

/**
 * A clock that only moves when told to or when waited on - the rate limit
 * of the geocoder is checked without sleeping. Every request "costs"
 * $jeAufruf seconds when advanced by the fake HTTP client.
 */
final class FakeTakt implements Takt
{
    /** @var list<float> */
    public array $pausen = [];

    public function __construct(public float $zeit = 1_800_000_000.0)
    {
    }

    public function jetzt(): float
    {
        return $this->zeit;
    }

    public function warten(float $sekunden): void
    {
        $this->pausen[] = $sekunden;
        $this->zeit += $sekunden;
    }

    public function vor(float $sekunden): void
    {
        $this->zeit += $sekunden;
    }
}
