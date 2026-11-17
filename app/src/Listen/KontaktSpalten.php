<?php

declare(strict_types=1);

namespace App\Listen;

/**
 * Address and contact of a member as columns for the export only
 * (docs/spec/09-listen-und-exporte.md section 2): with the birthday and the
 * honours list a letter can be written, a present brought or the member
 * called. Order and titles as in the full export (section 5).
 */
final class KontaktSpalten
{
    /** The member's columns to select; the table alias is "m". */
    public const string SQL = 'm.strasse, m.plz, m.ort, m.telefon, m.handy, m.email';

    private const array TITEL = [
        'strasse' => 'Straße',
        'plz' => 'PLZ',
        'ort' => 'Ort',
        'telefon' => 'Telefon',
        'handy' => 'Handy',
        'email' => 'E-Mail',
    ];

    /** @return list<Spalte> */
    public static function spalten(): array
    {
        return array_map(Spalte::fuerExport(...), array_keys(self::TITEL), array_values(self::TITEL));
    }

    /**
     * The values of a database row selected with SQL, by column key.
     *
     * @param array<string, mixed> $zeile
     * @return array<string, string>
     */
    public static function werte(array $zeile): array
    {
        $werte = [];
        foreach (array_keys(self::TITEL) as $schluessel) {
            $werte[$schluessel] = (string) ($zeile[$schluessel] ?? '');
        }

        return $werte;
    }
}
