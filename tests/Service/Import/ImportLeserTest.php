<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\FamilienRolle;
use App\Domain\Funktion;
use App\Domain\Geschlecht;
use App\Domain\Herkunft;
use App\Domain\Zahlart;
use App\Service\Import\ImportDatei;
use App\Service\Import\ImportLeser;
use App\Service\Import\ImportStammdaten;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading import.csv (docs/spec/10-import.md section 2) without a database:
 * a valid file, and every kind of error is reported - none is ignored and
 * none lets a line through. All names are made up.
 */
final class ImportLeserTest extends TestCase
{
    private const string KOPF = 'mitgliedsnummer;nachname;vorname;geschlecht;geburtsdatum;eintrittsdatum;aktiv;abteilungen;beitragsart;zahlart;iban;familie;familie_name;familie_rolle;funktionen;bemerkung';

    private function stammdaten(): ImportStammdaten
    {
        return new ImportStammdaten(
            [new Abteilung('Fußball', '09', 20, true, 2), new Abteilung('Gymnastik', '34', 40, true, 4), new Abteilung('Ohne Nummer', '', 50, true, 5)],
            [new Beitragsart('Aktiv', 8500, id: 1), new Beitragsart('Familie', 13000, id: 4)],
            [new Funktion('Übungsleiter', true, 10, true, 1)],
        );
    }

    private function lesen(string ...$zeilen): ImportDatei
    {
        return ImportLeser::lesen(self::KOPF . "\n" . implode("\n", $zeilen) . "\n", $this->stammdaten());
    }

    /** @return list<string> */
    private static function texte(ImportDatei $datei): array
    {
        return array_map(static fn($f): string => $f->text, $datei->fehler);
    }

    public function testAValidFileGivesTheMembersAsTheyWillBeSaved(): void
    {
        $datei = $this->lesen(
            '1;Beispiel;Erika;w;12.03.1980;01.01.2005;1;"09,34";Aktiv;lastschrift;de02 1002 0890 0014 5690 54;;;;Übungsleiter;"Zeile 1' . "\n" . 'Zeile 2"',
        );

        self::assertTrue($datei->istFehlerfrei(), implode(' | ', self::texte($datei)));
        self::assertCount(1, $datei->eintraege);
        $m = $datei->eintraege[0]->mitglied;
        self::assertSame(1, $m->mitgliedsnummer);
        self::assertSame('Beispiel', $m->nachname);
        self::assertSame(Geschlecht::W, $m->geschlecht);
        self::assertSame('1980-03-12', $m->geburtsdatum?->format('Y-m-d'));
        self::assertTrue($m->aktiv);
        self::assertSame([2, 4], $m->abteilungIds);
        self::assertSame(1, $m->beitragsartId);
        self::assertSame(Zahlart::Lastschrift, $m->zahlart);
        self::assertSame('DE02100208900014569054', $m->iban);
        self::assertSame([1], $m->funktionIds);
        self::assertSame("Zeile 1\nZeile 2", $m->bemerkung);
        self::assertSame(Herkunft::Import, $m->herkunft);
        self::assertSame('D', $m->land, 'an empty country is the default country');
        self::assertNull($m->familieId);
        self::assertNull($datei->eintraege[0]->familie);
    }

    public function testFamiliesAreLinkedByKeyWithNameAndRole(): void
    {
        $datei = $this->lesen(
            '1;Muster;Max;m;;;1;09;Familie;lastschrift;;F7;Familie Muster;zahler;;',
            '2;Muster;Mia;w;;;1;09;;lastschrift;;F7;;kind;;',
            '3;Solo;Sam;d;;;1;;;;;;;;;',
        );

        self::assertTrue($datei->istFehlerfrei());
        self::assertSame(['F7' => 'Familie Muster'], $datei->familien);
        self::assertSame('F7', $datei->eintraege[1]->familie);
        self::assertSame(FamilienRolle::Zahler, $datei->eintraege[0]->mitglied->familieRolle);
        self::assertSame(FamilienRolle::Kind, $datei->eintraege[1]->mitglied->familieRolle);
        self::assertNull($datei->eintraege[2]->familie);
    }

    public function testNumericFamilyKeysKeepTheirNamesAndAreNotRenumbered(): void
    {
        $datei = $this->lesen(
            '1;Muster;Max;m;;;1;09;Familie;lastschrift;;501;Familie Muster;zahler;;',
            '2;Muster;Mia;w;;;1;09;;lastschrift;;501;;kind;;',
            '3;Alt;Anna;w;;;1;09;Familie;lastschrift;;502;;zahler;;',
        );

        self::assertCount(2, $datei->familien);
        self::assertSame('Familie Muster', $datei->familien['501']);
        self::assertSame('Familie Alt', $datei->familien['502']);
    }

    public function testAFamilyWithoutANameIsCalledAfterItsFirstMember(): void
    {
        $datei = $this->lesen('1;Muster;Max;m;;;1;;;;;F7;;zahler;;');

        self::assertSame(['F7' => 'Familie Muster'], $datei->familien);
    }

    public function testDepartmentNumbersMatchWithoutTheLeadingZero(): void
    {
        $datei = $this->lesen('1;Beispiel;Erika;w;;;1;9;;;;;;;;');

        self::assertTrue($datei->istFehlerfrei(), implode(' | ', self::texte($datei)));
        self::assertSame([2], $datei->eintraege[0]->mitglied->abteilungIds);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fehlerarten(): array
    {
        return [
            'missing number' => [';Beispiel;Erika;;;;;;;;;;;;;', 'Mitgliedsnummer fehlt'],
            'number not a whole number' => ['12a;Beispiel;Erika;;;;;;;;;;;;;', 'keine ganze Zahl'],
            'number zero' => ['0;Beispiel;Erika;;;;;;;;;;;;;', 'keine ganze Zahl'],
            'missing surname' => ['1;;Erika;;;;;;;;;;;;;', 'Nachname fehlt'],
            'unknown department' => ['1;Beispiel;Erika;;;;;77;;;;;;;;', 'BLSV-Nummer „77“'],
            'unknown fee type' => ['1;Beispiel;Erika;;;;;;Gönner;;;;;;;', 'Beitragsart „Gönner“'],
            'unknown function' => ['1;Beispiel;Erika;;;;;;;;;;;;Zeugwart;', 'Funktion „Zeugwart“'],
            'broken birth date' => ['1;Beispiel;Erika;;31.02.1980;;;;;;;;;;;', 'geburtsdatum'],
            'broken entry date' => ['1;Beispiel;Erika;;;gestern;;;;;;;;;;', 'eintrittsdatum'],
            'unknown gender' => ['1;Beispiel;Erika;x;;;;;;;;;;;;', 'm, w oder d'],
            'unknown active value' => ['1;Beispiel;Erika;;;;ja;;;;;;;;;', '„aktiv“'],
            'unknown payment method' => ['1;Beispiel;Erika;;;;;;;bar;;;;;;', 'lastschrift oder selbst'],
            'family without role' => ['1;Beispiel;Erika;;;;;;;;;F1;;;;', 'Rolle zahler, elternteil oder kind'],
            'role without family' => ['1;Beispiel;Erika;;;;;;;;;;;kind;;', 'Familienrolle angegeben, aber keine Familie'],
        ];
    }

    #[DataProvider('fehlerarten')]
    public function testEveryKindOfErrorIsReportedAndNothingIsImportedFromThatLine(string $zeile, string $erwartet): void
    {
        $datei = $this->lesen($zeile);

        self::assertFalse($datei->istFehlerfrei());
        self::assertSame([], $datei->eintraege);
        self::assertStringContainsString($erwartet, implode(' | ', self::texte($datei)));
        self::assertSame(2, $datei->fehler[0]->zeile, 'the header is line 1');
    }

    public function testATooLongValueIsAnError(): void
    {
        $datei = $this->lesen('1;' . str_repeat('x', 101) . ';Erika;;;;;;;;;;;;;');

        self::assertStringContainsString('„nachname“ ist zu lang', implode(' | ', self::texte($datei)));
    }

    public function testADuplicateNumberMarksEveryLineCarryingIt(): void
    {
        $datei = $this->lesen('5;Eins;A;;;;;;;;;;;;;', '6;Zwei;B;;;;;;;;;;;;;', '5;Drei;C;;;;;;;;;;;;;');

        self::assertFalse($datei->istFehlerfrei());
        self::assertSame([2, 4], array_map(static fn($f): int => $f->zeile, $datei->fehler));
        self::assertStringContainsString('mehrfach', $datei->fehler[0]->text);
        self::assertStringContainsString('2, 4', $datei->fehler[0]->text);
        self::assertSame([6], array_map(static fn($e): int => $e->mitglied->mitgliedsnummer, $datei->eintraege), 'the duplicates stay out');
    }

    public function testAllErrorsOfAFileAreReportedAtOnce(): void
    {
        $datei = $this->lesen('1;A;a;;;;;77;;;;;;;;', '2;;b;;1.1.;;;;;;;;;;;');

        self::assertGreaterThanOrEqual(3, count($datei->fehler));
        self::assertSame([2, 3], array_values(array_unique(array_map(static fn($f): int => $f->zeile, $datei->fehler))));
    }

    public function testALineWithTheWrongNumberOfColumnsIsAnError(): void
    {
        $datei = $this->lesen('1;Beispiel;Erika');

        self::assertStringContainsString('statt 16 Spalten', implode(' | ', self::texte($datei)));
    }

    public function testRequiredColumnsMustExist(): void
    {
        $datei = ImportLeser::lesen("vorname;ort\nErika;Neustadt\n", $this->stammdaten());

        self::assertFalse($datei->istFehlerfrei());
        self::assertSame(0, $datei->fehler[0]->zeile);
        self::assertStringContainsString('„mitgliedsnummer“, „nachname“', $datei->fehler[0]->text);
    }

    public function testOptionalColumnsMayBeMissingAndUnknownOnesAreNamed(): void
    {
        $datei = ImportLeser::lesen("mitgliedsnummer;nachname;sonstiges\n1;Beispiel;x\n", $this->stammdaten());

        self::assertTrue($datei->istFehlerfrei());
        self::assertSame(['sonstiges'], $datei->unbekannteSpalten);
        self::assertSame('Beispiel', $datei->eintraege[0]->mitglied->nachname);
    }

    public function testABomWindowsLineEndingsAndEmptyLinesAreTolerated(): void
    {
        $csv = "\xEF\xBB\xBFmitgliedsnummer;nachname\r\n1;Beispiel\r\n\r\n2;Muster\r\n";

        $datei = ImportLeser::lesen($csv, $this->stammdaten());

        self::assertTrue($datei->istFehlerfrei(), implode(' | ', self::texte($datei)));
        self::assertCount(2, $datei->eintraege);
    }

    public function testLineNumbersCountLinesInsideQuotedCells(): void
    {
        $datei = $this->lesen('1;Eins;A;;;;;;;;;;;;;"a' . "\n" . 'b"', '2;Zwei;B;;;;;99;;;;;;;;');

        self::assertSame(4, $datei->fehler[0]->zeile, 'the first record spans lines 2 and 3');
    }

    public function testAFileThatIsNotUtf8OrEmptyOrWithoutMembersIsRejected(): void
    {
        $stamm = $this->stammdaten();

        self::assertStringContainsString('UTF-8', ImportLeser::lesen("mitgliedsnummer;nachname\n1;M\xFCller\n", $stamm)->fehler[0]->text);
        self::assertStringContainsString('leer', ImportLeser::lesen('', $stamm)->fehler[0]->text);
        self::assertStringContainsString('keine Mitglieder', ImportLeser::lesen("mitgliedsnummer;nachname\n", $stamm)->fehler[0]->text);
    }

    /** A wrong file with millions of short lines must not exhaust the memory. */
    public function testAFileWithFarTooManyLinesIsRefusedBeforeParsing(): void
    {
        $datei = ImportLeser::lesen(self::KOPF . "\n" . str_repeat("1;x\n", ImportLeser::MAX_ZEILEN + 1), $this->stammdaten());

        self::assertFalse($datei->istFehlerfrei());
        self::assertSame([], $datei->eintraege);
        self::assertStringContainsString('mehr als 20.000 Zeilen', $datei->fehler[0]->text);
    }
}
