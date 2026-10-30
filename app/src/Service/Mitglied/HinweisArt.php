<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

/**
 * The kinds of member hint (docs/spec/07-mitglieder.md section 6), in the
 * order of the table there - which is also the order Hinweise::fuer()
 * returns them in. The list "Auffälligkeiten" (09) filters by this kind.
 *
 * The values are stable identifiers (used in URLs and filters).
 */
enum HinweisArt: string
{
    case RentnerUnter67 = 'rentner-unter-67';
    case RentennachweisFehlt = 'rentennachweis-fehlt';
    case JugendbeitragAb18 = 'jugendbeitrag-ab-18';
    case NachweisAbgelaufen = 'nachweis-abgelaufen';
    case AktivPassivPasstNicht = 'aktiv-passiv-passt-nicht';
    case FamilieZuKlein = 'familie-zu-klein';
    case FamilieZahlerAnzahl = 'familie-zahler-anzahl';
    case ZahlerOhneFamilienbeitrag = 'zahler-ohne-familienbeitrag';
    case FamilienmitgliedMitEigenemBeitrag = 'familienmitglied-mit-eigenem-beitrag';
    case AndereAnschrift = 'andere-anschrift';
    case KindIst18 = 'kind-ist-18';
    case KeinBeitrag = 'kein-beitrag';
    case LastschriftOhneIban = 'lastschrift-ohne-iban';
    case BeitragsfreiOhneGrund = 'beitragsfrei-ohne-grund';
    case EintrittsdatumUnbekannt = 'eintrittsdatum-unbekannt';
    case BlsvAngabenFehlen = 'blsv-angaben-fehlen';
    case KarteNichtGefunden = 'karte-nicht-gefunden';
    case KarteUngefaehr = 'karte-ungefaehr';

    /**
     * The tab of the member where the hint is dealt with - the list
     * "Auffälligkeiten" opens the member there (issue #108); null: the
     * member's first tab.
     */
    public function reiter(): ?string
    {
        return match ($this) {
            self::KarteNichtGefunden, self::KarteUngefaehr => 'daten',
            default => null,
        };
    }

    /** Short name for filters and the list "Auffälligkeiten". */
    public function bezeichnung(): string
    {
        return match ($this) {
            self::RentnerUnter67 => 'Rentner unter 67',
            self::RentennachweisFehlt => 'Rentennachweis fehlt',
            self::JugendbeitragAb18 => 'Jugendbeitrag ab 18 ohne Nachweis',
            self::NachweisAbgelaufen => 'Nachweis abgelaufen',
            self::AktivPassivPasstNicht => 'Aktiv/passiv passt nicht zur Beitragsart',
            self::FamilieZuKlein => 'Familie zu klein',
            self::FamilieZahlerAnzahl => 'Familie ohne bzw. mit mehreren Zahlern',
            self::ZahlerOhneFamilienbeitrag => 'Zahler ohne Familienbeitrag',
            self::FamilienmitgliedMitEigenemBeitrag => 'Familienmitglied mit eigenem Beitrag',
            self::AndereAnschrift => 'Andere Anschrift als die Familie',
            self::KindIst18 => 'Kind ist 18',
            self::KeinBeitrag => 'Kein Beitrag',
            self::LastschriftOhneIban => 'Lastschrift ohne gültige IBAN',
            self::BeitragsfreiOhneGrund => 'Beitragsfrei ohne Grund',
            self::EintrittsdatumUnbekannt => 'Eintrittsdatum unbekannt',
            self::BlsvAngabenFehlen => 'Pflichtangaben für BLSV fehlen',
            self::KarteNichtGefunden => 'Anschrift auf der Karte nicht gefunden',
            self::KarteUngefaehr => 'Anschrift auf der Karte nur ungefähr gefunden',
        };
    }
}
