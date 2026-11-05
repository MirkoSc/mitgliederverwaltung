<?php

declare(strict_types=1);

namespace App\Service\Statistik;

/**
 * "Top 10 + Sonstige" of places and postcodes (09 section 7): the ten
 * largest values, ties by name; the rest in one row "Sonstige", then
 * "ohne Angabe" for the empty text.
 */
final class Rangliste
{
    public const int ANZAHL = 10;

    public const string OHNE_ANGABE = Merkmale::OHNE_ANGABE;

    /**
     * Adds $wert under $text: grouped case-insensitively and without
     * surrounding blanks, the first spelling met names the row.
     *
     * @param array<string, array{name: string, wert: int, personen: int}> $sammlung
     */
    public static function zaehlen(array &$sammlung, string $text, int $wert, int $personen = 1): void
    {
        $name = trim($text);
        // A prefix keeps numeric postcodes string keys.
        $schluessel = 'k' . mb_strtolower($name);
        $sammlung[$schluessel] ??= ['name' => $name, 'wert' => 0, 'personen' => 0];
        $sammlung[$schluessel]['wert'] += $wert;
        $sammlung[$schluessel]['personen'] += $personen;
    }

    /**
     * @param array<string, array{name: string, wert: int, personen: int}> $sammlung from zaehlen()
     * @param string $mehrzahl what the rest row counts, e.g. "Orte"
     * @param bool $mitPersonen fill the column "anzahl" (sums of money)
     * @return list<AuswertungsZeile>
     */
    public static function zeilen(array $sammlung, string $mehrzahl, bool $mitPersonen = false): array
    {
        $ohne = $sammlung['k'] ?? null;
        unset($sammlung['k']);
        $eintraege = array_values($sammlung);
        usort($eintraege, static fn(array $a, array $b): int => [$b['wert'], $a['name']] <=> [$a['wert'], $b['name']]);

        $zeile = static fn(string $name, int $wert, int $personen, bool $istOhne = false): AuswertungsZeile
            => new AuswertungsZeile($name, [$wert], $istOhne, $mitPersonen ? $personen : null);

        $zeilen = [];
        foreach (array_slice($eintraege, 0, self::ANZAHL) as $eintrag) {
            $zeilen[] = $zeile($eintrag['name'], $eintrag['wert'], $eintrag['personen']);
        }
        $rest = array_slice($eintraege, self::ANZAHL);
        if ($rest !== []) {
            $zeilen[] = $zeile(
                'Sonstige (' . count($rest) . ' ' . $mehrzahl . ')',
                array_sum(array_column($rest, 'wert')),
                array_sum(array_column($rest, 'personen')),
                true,
            );
        }
        if ($ohne !== null) {
            $zeilen[] = $zeile(self::OHNE_ANGABE, $ohne['wert'], $ohne['personen'], true);
        }

        return $zeilen;
    }
}
