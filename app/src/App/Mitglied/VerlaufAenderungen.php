<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\MitgliedVersion;
use App\Domain\Zahlart;
use App\Support\Datum;

/**
 * What changed between two versions of a member, as "alt -> neu" for the
 * tab "Verlauf" (docs/spec/07-mitglieder.md section 7). Only the fields of
 * Mitglied::daten() are compared (ids for family, fee type, departments and
 * functions); the names shown come from the versions themselves
 * (MitgliedVersion::bezeichnungen()), so a rename or delete later does not
 * rewrite the history.
 */
final class VerlaufAenderungen
{
    /** Label per key of Mitglied::daten(), in the order of the form. */
    private const array FELDER = [
        'mitgliedsnummer' => 'Mitgliedsnummer',
        'anrede' => 'Anrede',
        'titel' => 'Titel',
        'vorname' => 'Vorname',
        'nachname' => 'Nachname',
        'namenszusatz' => 'Namenszusatz',
        'geschlecht' => 'Geschlecht',
        'geburtsdatum' => 'Geburtsdatum',
        'strasse' => 'Straße',
        'plz' => 'PLZ',
        'ort' => 'Ort',
        'land' => 'Land',
        'telefon' => 'Telefon',
        'handy' => 'Handy',
        'email' => 'E-Mail',
        'eintrittsdatum' => 'Eintritt',
        'austrittsdatum' => 'Austritt',
        'austrittsgrund' => 'Austrittsgrund',
        'aktiv' => 'Status',
        'abteilungen' => 'Abteilungen',
        'beitragsart_id' => 'Beitragsart',
        'zahlart' => 'Zahlart',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'kontoinhaber' => 'Kontoinhaber',
        'mandatsreferenz' => 'Mandatsreferenz',
        'mandatsdatum' => 'Mandatsdatum',
        'familie_id' => 'Familie',
        'familie_rolle' => 'Rolle in der Familie',
        'funktionen' => 'Funktionen',
        'lizenznummer' => 'Lizenznummer',
        'bemerkung' => 'Bemerkung',
    ];

    private const string LEER = '–';

    /**
     * The changed fields from $alt to $neu; with $alt null (the first
     * version) nothing is listed - there was no "before".
     *
     * @return list<array{feld: string, alt: string, neu: string}>
     */
    public static function zwischen(?MitgliedVersion $alt, MitgliedVersion $neu): array
    {
        if ($alt === null) {
            return [];
        }

        $aenderungen = [];
        foreach (self::FELDER as $schluessel => $bezeichnung) {
            $vorher = $alt->daten[$schluessel] ?? null;
            $nachher = $neu->daten[$schluessel] ?? null;
            if ($vorher === $nachher) {
                continue;
            }
            $aenderungen[] = [
                'feld' => $bezeichnung,
                'alt' => self::anzeige($schluessel, $vorher, $alt),
                'neu' => self::anzeige($schluessel, $nachher, $neu),
            ];
        }

        return $aenderungen;
    }

    private static function anzeige(string $schluessel, mixed $wert, MitgliedVersion $version): string
    {
        $namen = $version->bezeichnungen();

        return match ($schluessel) {
            'abteilungen', 'funktionen' => self::liste($wert, is_array($namen[$schluessel] ?? null) ? $namen[$schluessel] : []),
            'familie_id' => self::name($wert, $namen['familie'] ?? null, 'Familie'),
            'beitragsart_id' => self::name($wert, $namen['beitragsart'] ?? null, 'Beitragsart'),
            'aktiv' => $wert === true ? 'aktiv' : 'passiv',
            'geschlecht' => is_string($wert) ? (Geschlecht::tryFrom($wert)?->bezeichnung() ?? $wert) : self::LEER,
            'zahlart' => is_string($wert) ? (Zahlart::tryFrom($wert)?->bezeichnung() ?? $wert) : self::LEER,
            'familie_rolle' => is_string($wert) ? (FamilienRolle::tryFrom($wert)?->bezeichnung() ?? $wert) : self::LEER,
            'geburtsdatum', 'eintrittsdatum', 'austrittsdatum', 'mandatsdatum' => is_string($wert) && $wert !== '' ? Datum::anzeigen($wert) : self::LEER,
            default => is_scalar($wert) && (string) $wert !== '' ? (string) $wert : self::LEER,
        };
    }

    /** @param array<array-key, mixed> $namen id => name of that version */
    private static function liste(mixed $ids, array $namen): string
    {
        if (!is_array($ids) || $ids === []) {
            return self::LEER;
        }

        return implode(', ', array_map(
            static fn(mixed $id): string => is_scalar($namen[(string) $id] ?? null) ? (string) $namen[(string) $id] : '(Nr. ' . (is_scalar($id) ? $id : '?') . ')',
            $ids,
        ));
    }

    private static function name(mixed $id, mixed $name, string $art): string
    {
        if ($id === null) {
            return self::LEER;
        }

        return is_string($name) && $name !== '' ? $name : $art . ' (Nr. ' . (is_scalar($id) ? $id : '?') . ')';
    }
}
