<?php

declare(strict_types=1);

namespace App\Service\Mitglied;

use App\Domain\AktivArt;
use App\Domain\BeitragsRegel;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\NachweisArt;
use App\Domain\Zahlart;
use App\Service\Karte\GeoStatus;
use App\Support\Iban;

/**
 * The member hints of docs/spec/07-mitglieder.md section 6 as a pure
 * function: the same member and context always give the same hints, nothing
 * is read or stored. Hints warn and never block (CLAUDE.md).
 *
 * Ages are whole years on the context's day, "valid until" counts through
 * that day itself (a proof valid until today is still valid today).
 */
final class Hinweise
{
    /** Pension age the rule "Rentner unter 67" checks. */
    public const int RENTENALTER = 67;

    /** Age from which a youth fee and a child in a family are hinted. */
    public const int VOLLJAEHRIG = 18;

    /**
     * @return list<Hinweis> in the order of the table in 07 section 6
     */
    public static function fuer(Mitglied $mitglied, HinweisKontext $kontext): array
    {
        $tag = $kontext->stichtag;
        $alter = $mitglied->alterAm($tag);
        $art = $mitglied->beitragsartId === null ? null : ($kontext->beitragsarten[$mitglied->beitragsartId] ?? null);
        $hinweise = [];
        $melden = static function (HinweisArt $kind, string $text) use (&$hinweise): void {
            $hinweise[] = new Hinweis($kind, $text);
        };

        // Fee type rules and proofs.
        $gueltig = static function (NachweisArt ...$arten) use ($kontext, $tag): bool {
            foreach ($kontext->nachweise as $nachweis) {
                if (in_array($nachweis->art, $arten, true) && $nachweis->istGueltigAm($tag)) {
                    return true;
                }
            }

            return false;
        };

        if ($art?->hatRegel(BeitragsRegel::Rentner) === true) {
            if ($alter !== null && $alter < self::RENTENALTER) {
                $melden(HinweisArt::RentnerUnter67, sprintf(
                    'Die Beitragsart „%s“ ist für Rentner, das Mitglied ist aber erst %d Jahre alt. Bitte die Beitragsart prüfen.',
                    $art->name,
                    $alter,
                ));
            }
            if (!$gueltig(NachweisArt::Rente)) {
                $melden(HinweisArt::RentennachweisFehlt, sprintf(
                    'Für die Beitragsart „%s“ gibt es keinen gültigen Rentennachweis. Bitte einen Nachweis eintragen.',
                    $art->name,
                ));
            }
        }

        if ($art?->hatRegel(BeitragsRegel::Jugend) === true && $alter !== null && $alter >= self::VOLLJAEHRIG
            && !$gueltig(NachweisArt::Studium, NachweisArt::Ausbildung)) {
            $melden(HinweisArt::JugendbeitragAb18, sprintf(
                'Die Beitragsart „%s“ gilt bis 18; das Mitglied ist %d und hat keinen gültigen Nachweis über Studium oder Ausbildung. Bitte einen Nachweis eintragen oder die Beitragsart ändern.',
                $art->name,
                $alter,
            ));
        }

        foreach ($kontext->nachweise as $nachweis) {
            if ($nachweis->istAbgelaufenAm($tag) && $nachweis->gueltigBis !== null) {
                $melden(HinweisArt::NachweisAbgelaufen, sprintf(
                    'Der Nachweis „%s“ ist seit dem %s abgelaufen. Bitte einen neuen Nachweis eintragen.',
                    $nachweis->art->bezeichnung(),
                    $nachweis->gueltigBis->format('d.m.Y'),
                ));
            }
        }

        if ($art !== null && (
            ($art->aktivArt === AktivArt::Aktiv && !$mitglied->aktiv)
            || ($art->aktivArt === AktivArt::Passiv && $mitglied->aktiv)
        )) {
            $melden(HinweisArt::AktivPassivPasstNicht, sprintf(
                'Die Beitragsart „%s“ ist für %s Mitglieder, das Mitglied ist aber %s. Bitte Häkchen oder Beitragsart prüfen.',
                $art->name,
                $art->aktivArt->bezeichnung(),
                $mitglied->aktiv ? 'aktiv' : 'passiv',
            ));
        }

        // Family rules.
        $familie = self::familie($mitglied, $kontext);
        if ($mitglied->familieId !== null) {
            if (count($familie) < 2) {
                $melden(HinweisArt::FamilieZuKlein, 'Die Familie hat weniger als 2 Personen. Bitte weitere Personen zuordnen oder die Familie auflösen.');
            }
            $zahler = array_values(array_filter($familie, static fn(Mitglied $m): bool => $m->familieRolle === FamilienRolle::Zahler));
            if (count($zahler) !== 1) {
                $melden(HinweisArt::FamilieZahlerAnzahl, count($zahler) === 0
                    ? 'In der Familie ist niemand als Zahler eingetragen. Bitte genau einen Zahler bestimmen.'
                    : sprintf('In der Familie sind %d Personen als Zahler eingetragen. Bitte genau einen Zahler bestimmen.', count($zahler)));
            }
        }

        if ($mitglied->familieRolle === FamilienRolle::Zahler && $mitglied->familieId !== null && $art?->hatRegel(BeitragsRegel::Familie) !== true) {
            $melden(HinweisArt::ZahlerOhneFamilienbeitrag, 'Der Zahler der Familie hat keine Beitragsart mit der Regel „Familie“. Bitte die Beitragsart prüfen.');
        }

        if ($mitglied->familieId !== null
            && in_array($mitglied->familieRolle, [FamilienRolle::Elternteil, FamilienRolle::Kind], true)
            && $mitglied->beitragsartId !== null) {
            $melden(HinweisArt::FamilienmitgliedMitEigenemBeitrag, 'Elternteile und Kinder zahlen normalerweise über den Zahler der Familie, haben hier aber eine eigene Beitragsart. Bitte prüfen.');
        }

        if ($mitglied->familieId !== null && $mitglied->familieRolle !== FamilienRolle::Zahler) {
            $zahler = self::ersterZahler($familie, $mitglied);
            if ($zahler !== null && self::anschrift($mitglied) !== self::anschrift($zahler)) {
                $melden(HinweisArt::AndereAnschrift, 'Straße oder PLZ weichen von der Anschrift des Zahlers der Familie ab. Bitte beide Anschriften prüfen.');
            }
        }

        if ($mitglied->familieRolle === FamilienRolle::Kind && $alter !== null && $alter >= self::VOLLJAEHRIG) {
            $melden(HinweisArt::KindIst18, sprintf('Das Kind ist %d Jahre alt. Bitte prüfen, ob es einen eigenen Beitrag zahlen soll.', $alter));
        }

        // Fee, payment and base data.
        if ($mitglied->beitragsartId === null && $mitglied->familieId === null && !$mitglied->istEhemalig($tag)) {
            $melden(HinweisArt::KeinBeitrag, 'Das Mitglied hat weder eine Beitragsart noch eine Familie. Bitte eine Beitragsart wählen.');
        }

        if ($mitglied->zahlart === Zahlart::Lastschrift && !Iban::istGueltig($mitglied->iban)
            && !($mitglied->iban === '' && self::zahltUeberFamilie($mitglied))) {
            $melden(HinweisArt::LastschriftOhneIban, $mitglied->iban === ''
                ? 'Die Zahlart ist Lastschrift, aber es ist keine IBAN eingetragen. Bitte die IBAN ergänzen oder die Zahlart ändern.'
                : 'Die Zahlart ist Lastschrift, aber die IBAN ist ungültig – die Prüfziffer passt nicht. Bitte die IBAN prüfen.');
        }

        if ($art?->hatRegel(BeitragsRegel::Beitragsfrei) === true && trim($mitglied->bemerkung) === '') {
            $melden(HinweisArt::BeitragsfreiOhneGrund, 'Das Mitglied ist beitragsfrei, aber die Bemerkung nennt keinen Grund. Bitte den Grund in die Bemerkung schreiben.');
        }

        if ($mitglied->eintrittsdatum === null) {
            $melden(HinweisArt::EintrittsdatumUnbekannt, 'Das Eintrittsdatum ist unbekannt. Bitte ergänzen – ohne Eintrittsdatum gibt es keine Ehrung.');
        }

        if ($mitglied->geschlecht === null || $mitglied->geburtsdatum === null) {
            $fehlt = array_filter([
                $mitglied->geschlecht === null ? 'das Geschlecht' : null,
                $mitglied->geburtsdatum === null ? 'das Geburtsdatum' : null,
            ]);
            $melden(HinweisArt::BlsvAngabenFehlen, sprintf('Für die BLSV-Meldung fehlt %s. Bitte ergänzen.', implode(' und ', $fehlt)));
        }

        // The map (issue #108): only what the search left to fix by hand.
        // Found, set by hand and not asked yet are no hint.
        if (!$mitglied->istEhemalig($tag)) {
            if ($kontext->geoStatus === GeoStatus::NichtGefunden) {
                $melden(HinweisArt::KarteNichtGefunden, 'Die Anschrift wurde auf der Karte nicht gefunden. Bitte im Reiter „Daten“ unter „Wohnort auf der Karte“ eine Such-Anschrift angeben oder den Punkt von Hand setzen.');
            } elseif ($kontext->geoStatus === GeoStatus::Ungefaehr) {
                $melden(HinweisArt::KarteUngefaehr, 'Die Anschrift wurde auf der Karte nur ungefähr gefunden: PLZ und Ort, nicht die Straße. Bitte im Reiter „Daten“ unter „Wohnort auf der Karte“ die Suche korrigieren oder den Punkt von Hand setzen.');
            }
        }

        return $hinweise;
    }

    /**
     * The persons of the member's family with the member as given (it may
     * hold changes not stored yet) in place of the stored one.
     *
     * @return list<Mitglied>
     */
    private static function familie(Mitglied $mitglied, HinweisKontext $kontext): array
    {
        if ($mitglied->familieId === null) {
            return [];
        }
        $personen = array_values(array_filter(
            $kontext->familie,
            static fn(Mitglied $m): bool => $mitglied->id === null || $m->id !== $mitglied->id,
        ));
        $personen[] = $mitglied;

        return $personen;
    }

    /**
     * @param list<Mitglied> $familie
     */
    private static function ersterZahler(array $familie, Mitglied $ausser): ?Mitglied
    {
        foreach ($familie as $person) {
            if ($person->familieRolle === FamilienRolle::Zahler && $person !== $ausser) {
                return $person;
            }
        }

        return null;
    }

    /** Street and postcode, compared without case and surrounding blanks. */
    private static function anschrift(Mitglied $m): string
    {
        return mb_strtolower(trim($m->strasse)) . '|' . mb_strtolower(trim($m->plz));
    }

    /** A parent or child without a fee type of their own is paid for by the payer. */
    private static function zahltUeberFamilie(Mitglied $m): bool
    {
        return $m->familieId !== null
            && in_array($m->familieRolle, [FamilienRolle::Elternteil, FamilienRolle::Kind], true)
            && $m->beitragsartId === null;
    }
}
