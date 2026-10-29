<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Listen\Spalte;
use App\Listen\SpaltenTyp;
use App\Support\Datum;
use App\Support\Geld;

/** One cell of a list, read according to its column - shared by both writers. */
final class Zellwert
{
    /** The day of a date cell, or null when the cell is empty or not a date. */
    public static function tag(Spalte $spalte, string|int|\DateTimeInterface|null $wert): ?\DateTimeImmutable
    {
        if ($spalte->typ !== SpaltenTyp::Datum || $wert === null || $wert === '') {
            return null;
        }
        if ($wert instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($wert);
        }

        return Datum::ausEingabe((string) $wert);
    }

    /**
     * The cell as text the way a German Excel reads it: "31.12.2026",
     * "1234,56", "42". Anything not matching its column type stays as typed.
     */
    public static function text(Spalte $spalte, string|int|\DateTimeInterface|null $wert): string
    {
        if ($wert === null || $wert === '') {
            return '';
        }

        return match ($spalte->typ) {
            SpaltenTyp::Datum => self::tag($spalte, $wert)?->format('d.m.Y') ?? (string) ($wert instanceof \DateTimeInterface ? '' : $wert),
            SpaltenTyp::Geld => is_int($wert) ? Geld::eingabeWert($wert) : (string) $wert,
            SpaltenTyp::Zahl, SpaltenTyp::Text => $wert instanceof \DateTimeInterface ? $wert->format('d.m.Y') : (string) $wert,
        };
    }
}
