<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\Geschlecht;

/**
 * The BLSV template "Mitgliederimport" (BE2025) and the assumptions about it
 * that are still open (docs/spec/09-listen-und-exporte.md section 4, L3).
 * Everything that may change when the club delivers the missing facts is in
 * this one place.
 */
final class BlsvFormat
{
    /** Column key => title, in the order of the template. */
    public const array SPALTEN = [
        'titel' => 'Titel',
        'name' => 'Name',
        'vorname' => 'Vorname',
        'namenszusatz' => 'Namenszusatz',
        'geschlecht' => 'Geschlecht',
        'geburtsdatum' => 'Geburtsdatum',
        'spartenkennzeichen' => 'Spartenkennzeichen',
    ];

    /** Name of the association in the club data (11 section 1) whose number is shown above the export. */
    public const string VERBAND = 'BLSV';

    /** Assumption: one row per member and department (false = one row per member). */
    public const bool ZEILE_JE_ABTEILUNG = true;

    /** Assumption: a member without a department stays in the file with an empty Spartenkennzeichen. */
    public const bool OHNE_ABTEILUNG_AUFNEHMEN = true;

    /** Assumption: the day when no date is chosen is 1 January of the current year. */
    public const string STICHTAG_MONAT_TAG = '01-01';

    /** Assumption: the value written for each gender. */
    public static function geschlecht(?Geschlecht $geschlecht): string
    {
        return $geschlecht?->value ?? '';
    }

    public static function standardStichtag(\DateTimeImmutable $heute): string
    {
        return $heute->format('Y') . '-' . self::STICHTAG_MONAT_TAG;
    }
}
