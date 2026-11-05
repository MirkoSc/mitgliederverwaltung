<?php

declare(strict_types=1);

namespace App\Service\Statistik;

/** The tiles on top of the member statistics (09 section 7): current members only. */
final readonly class Kennzahlen
{
    public function __construct(
        public int $gesamt,
        public int $aktiv,
        public int $passiv,
        public int $weiblich,
        public int $maennlich,
        public int $divers,
        public int $ohneGeschlecht,
        /** Younger than 18 (Kinder und Jugendliche). */
        public int $minderjaehrig,
        /** Average age in tenths of a year; null without any birth date. */
        public ?int $durchschnittsalterZehntel,
    ) {
    }

    /** "41,3" - or null without any birth date. */
    public function durchschnittsalter(): ?string
    {
        return self::zehntel($this->durchschnittsalterZehntel);
    }

    /** Average of whole years in tenths, rounded half up; null for none. */
    public static function mittelZehntel(int $summe, int $anzahl): ?int
    {
        return $anzahl === 0 ? null : intdiv(20 * $summe + $anzahl, 2 * $anzahl);
    }

    public static function zehntel(?int $zehntel): ?string
    {
        return $zehntel === null ? null : intdiv($zehntel, 10) . ',' . ($zehntel % 10);
    }
}
