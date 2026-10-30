<?php

declare(strict_types=1);

namespace App\Service\Karte;

/**
 * The answer of the geocoder for one address: found (with coordinates), not
 * found, or a failure of the service. A failure is not stored - the address
 * is asked again later - and carries a short reason without any address in
 * it (CLAUDE.md section 4).
 */
final readonly class GeoErgebnis
{
    private function __construct(
        public ?GeoStatus $status,
        public ?float $lat = null,
        public ?float $lon = null,
        public string $fehler = '',
    ) {
    }

    public static function gefunden(float $lat, float $lon): self
    {
        return new self(GeoStatus::Gefunden, $lat, $lon);
    }

    public static function nichtGefunden(): self
    {
        return new self(GeoStatus::NichtGefunden);
    }

    public static function fehler(string $grund): self
    {
        return new self(null, fehler: $grund);
    }

    public function istFehler(): bool
    {
        return $this->status === null;
    }
}
