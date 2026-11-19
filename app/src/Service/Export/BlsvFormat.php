<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Domain\Geschlecht;

/**
 * The BLSV template "Mitgliederimport" (BE2025) and its settled format rules
 * (docs/spec/09-listen-und-exporte.md section 4). Everything about the format
 * is in this one place.
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

    /** One row per member (not per member and department). */
    public const bool ZEILE_JE_ABTEILUNG = false;

    /** Separator of several Spartenkennzeichen in one cell. */
    public const string TRENNER = ', ';

    /** Birthdate is written as text in this format, so the upload reads what is seen. */
    public const string DATUMSFORMAT = 'd.m.Y';

    /** A member without a department stays in the file with an empty Spartenkennzeichen (and is named in the hints). */
    public const bool OHNE_ABTEILUNG_AUFNEHMEN = true;

    /** The day when no date is chosen is 1 January of the current year. */
    public const string STICHTAG_MONAT_TAG = '01-01';

    /** The value written for each gender: m / w / d. */
    public static function geschlecht(?Geschlecht $geschlecht): string
    {
        return $geschlecht?->value ?? '';
    }

    /** "09" becomes "9", "34" stays, empty stays empty (the department keeps "09" in the database). */
    public static function ohneFuehrendeNull(string $nummer): string
    {
        $nummer = trim($nummer);
        if ($nummer === '') {
            return '';
        }
        $ohne = ltrim($nummer, '0');

        return $ohne === '' ? '0' : $ohne;
    }

    /**
     * One cell for all department numbers: ascending by number, each once, "9, 34".
     *
     * @param list<string> $nummern
     */
    public static function spartenkennzeichen(array $nummern): string
    {
        $zahlen = [];
        foreach ($nummern as $nummer) {
            $ohne = self::ohneFuehrendeNull($nummer);
            if ($ohne !== '') {
                $zahlen[$ohne] = $ohne;
            }
        }
        uksort($zahlen, static fn(int|string $a, int|string $b): int => ctype_digit((string) $a) && ctype_digit((string) $b)
            ? (int) $a <=> (int) $b
            : strcmp((string) $a, (string) $b));

        return implode(self::TRENNER, $zahlen);
    }

    public static function standardStichtag(\DateTimeImmutable $heute): string
    {
        return $heute->format('Y') . '-' . self::STICHTAG_MONAT_TAG;
    }
}
