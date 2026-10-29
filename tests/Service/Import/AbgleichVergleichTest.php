<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\FamilienRolle;
use App\Domain\Funktion;
use App\Domain\Herkunft;
use App\Domain\Mitglied;
use App\Service\Import\AbgleichFelder;
use App\Service\Import\AbgleichVergleich;
use App\Service\Import\ImportDatei;
use App\Service\Import\ImportLeser;
use App\Service\Import\ImportStammdaten;
use PHPUnit\Framework\TestCase;

/**
 * The sorting of a reconciliation (docs/spec/10-import.md section 4)
 * without a database: Neu / Fehlt in der Datei / Geändert / unverändert,
 * manual and former members never "missing", families by key. All names
 * are made up.
 */
final class AbgleichVergleichTest extends TestCase
{
    private const string KOPF = 'mitgliedsnummer;nachname;vorname;ort;aktiv;abteilungen;beitragsart;iban;bic;familie;familie_name;familie_rolle';

    private function datei(string ...$zeilen): ImportDatei
    {
        $datei = ImportLeser::lesen(self::KOPF . "\n" . implode("\n", $zeilen) . "\n", new ImportStammdaten(
            [new Abteilung('Fußball', '09', 20, true, 2)],
            [new Beitragsart('Aktiv', 8500, id: 1), new Beitragsart('Familie', 13000, id: 4)],
            [new Funktion('Übungsleiter', true, 10, true, 1)],
        ));
        self::assertSame([], $datei->fehler);

        return $datei;
    }

    /** A member as the first import would have saved it from the line "nr;Name<nr>;Vorname<nr>;Altstadt;1;09;Aktiv;;;;;". */
    private static function importiert(int $nr, array $werte = []): Mitglied
    {
        return new Mitglied(
            mitgliedsnummer: $nr,
            nachname: 'Name' . $nr,
            vorname: 'Vorname' . $nr,
            ort: 'Altstadt',
            abteilungIds: [2],
            beitragsartId: 1,
            herkunft: Herkunft::Import,
            id: 100 + $nr,
            version: 1,
        )->mit($werte);
    }

    private static function zeile(int $nr, string $ort = 'Altstadt', string $familie = ';;'): string
    {
        return sprintf('%d;Name%d;Vorname%d;%s;1;09;Aktiv;;;%s', $nr, $nr, $nr, $ort, $familie);
    }

    public function testTheSortingIntoNewMissingChangedAndUnchanged(): void
    {
        $datei = $this->datei(self::zeile(1), self::zeile(2, 'Neustadt'), self::zeile(5));
        $bestand = [self::importiert(1), self::importiert(2), self::importiert(3)];

        $vergleich = AbgleichVergleich::aus($datei, $bestand, [], []);

        self::assertSame([5], array_map(static fn($e): int => $e->mitglied->mitgliedsnummer, $vergleich->neu));
        self::assertSame([3], array_map(static fn($f): int => $f->mitglied->mitgliedsnummer, $vergleich->fehlt));
        self::assertSame([2], array_map(static fn($a): int => $a->vorher->mitgliedsnummer, $vergleich->geaendert));
        self::assertSame(['ort' => ['Altstadt', 'Neustadt']], $vergleich->geaendert[0]->felder);
        self::assertSame(1, $vergleich->unveraendert);
        self::assertFalse($vergleich->istLeer());
    }

    public function testManualAndFormerMembersAreNeverMissing(): void
    {
        $datei = $this->datei(self::zeile(1));
        $bestand = [
            self::importiert(1),
            self::importiert(2, ['herkunft' => Herkunft::Manuell]),
            self::importiert(3, ['austrittsdatum' => new \DateTimeImmutable('2025-12-31')]),
            // Leaving in the future counts as having left already: the date is set.
            self::importiert(4, ['austrittsdatum' => new \DateTimeImmutable('2099-12-31')]),
        ];

        $vergleich = AbgleichVergleich::aus($datei, $bestand, [], []);

        self::assertSame([], $vergleich->fehlt);
        self::assertTrue($vergleich->istLeer());
    }

    public function testAManualMemberInTheFileIsComparedLikeAnyOther(): void
    {
        $datei = $this->datei(self::zeile(1, 'Neustadt'));

        $vergleich = AbgleichVergleich::aus($datei, [self::importiert(1, ['herkunft' => Herkunft::Manuell])], [], []);

        self::assertCount(1, $vergleich->geaendert);
    }

    public function testOnlyMembersWithoutAnnualFeesMayBeDeleted(): void
    {
        $datei = $this->datei(self::zeile(9));

        $vergleich = AbgleichVergleich::aus($datei, [self::importiert(1), self::importiert(2)], [], [101 => true]);

        self::assertFalse($vergleich->fehlt[0]->kannLoeschen, 'member 1 (id 101) has a fee');
        self::assertTrue($vergleich->fehlt[1]->kannLoeschen);
    }

    public function testTheIbanAndBicAreComparedAsTheyAreStored(): void
    {
        $datei = $this->datei('1;Name1;Vorname1;Altstadt;1;09;Aktiv;de02 1002 0890 0014 5690 54;bfswde33xxx;;;');

        $vergleich = AbgleichVergleich::aus($datei, [self::importiert(1, ['iban' => 'DE02100208900014569054', 'bic' => 'BFSWDE33XXX'])], [], []);

        self::assertSame(1, $vergleich->unveraendert);
    }

    public function testFamiliesAreMatchedByKeyAndANewKeyIsANewFamily(): void
    {
        $datei = $this->datei(
            self::zeile(1, familie: 'F1;Familie Eins;zahler'),
            self::zeile(2, familie: 'F2;Familie Zwei;kind'),
            self::zeile(3, familie: 'F1;;kind'),
        );
        $bestand = [
            self::importiert(1, ['familieId' => 11, 'familieRolle' => FamilienRolle::Zahler]),
            self::importiert(2),
            // Same family, other role.
            self::importiert(3, ['familieId' => 11, 'familieRolle' => FamilienRolle::Elternteil]),
        ];

        $vergleich = AbgleichVergleich::aus($datei, $bestand, ['F1' => 11], []);

        self::assertSame(1, $vergleich->unveraendert);
        self::assertSame(
            ['familie_id' => [null, AbgleichVergleich::NEUE_FAMILIE . 'F2'], 'familie_rolle' => [null, 'kind']],
            $vergleich->geaendert[0]->felder,
        );
        self::assertSame(['familie_rolle' => ['elternteil', 'kind']], $vergleich->geaendert[1]->felder);
    }

    public function testTheFieldsReadAsNamesAndGermanValues(): void
    {
        $felder = new AbgleichFelder([2 => 'Fußball', 4 => 'Gymnastik'], [1 => 'Aktiv'], [1 => 'Übungsleiter'], [11 => 'Familie Eins'], ['F2' => 'Familie Zwei']);

        self::assertSame('Abteilungen', $felder->name('abteilungen'));
        self::assertSame('Fußball, Gymnastik', $felder->wert('abteilungen', [4, 2]));
        self::assertSame('31.12.2025', $felder->wert('austrittsdatum', '2025-12-31'));
        self::assertSame('passiv', $felder->wert('aktiv', false));
        self::assertSame('Aktiv', $felder->wert('beitragsart_id', 1));
        self::assertSame('Familie Eins', $felder->wert('familie_id', 11));
        self::assertSame('neue Familie „Familie Zwei“', $felder->wert('familie_id', AbgleichVergleich::NEUE_FAMILIE . 'F2'));
        self::assertSame('Kind', $felder->wert('familie_rolle', 'kind'));
        self::assertSame('', $felder->wert('ort', ''));
        self::assertSame('', $felder->wert('abteilungen', []));
    }
}
