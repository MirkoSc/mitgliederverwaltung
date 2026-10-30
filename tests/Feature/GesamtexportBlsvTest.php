<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Geschlecht;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Session;
use App\Http\StreamResponse;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\MitgliedRepository;
use App\Service\Verein\VereinRepository;
use App\Tests\Support\AppTestCase;

/**
 * "BLSV-Meldung" and "Alle Mitglieder" through the application (docs/spec/
 * 09-listen-und-exporte.md sections 4 and 5, issue #28). Made-up data only.
 */
final class GesamtexportBlsvTest extends AppTestCase
{
    private \PDO $pdo;

    protected function pdoFabrik(): ?\Closure
    {
        $this->pdo = ($this->mitDatenbank())();

        return fn(): \PDO => $this->pdo;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $abteilungen = new AbteilungRepository($this->pdo)->alle();
        $badminton = (int) $abteilungen[0]->id;
        $fussball = (int) $abteilungen[1]->id;
        $art = (int) new BeitragsartRepository($this->pdo)->alle()[0]->id;
        $repo = new MitgliedRepository($this->pdo);
        foreach ([
            new Mitglied(1001, 'Mustermann', vorname: 'Erika', titel: 'Dr.', namenszusatz: 'von', geschlecht: Geschlecht::W, geburtsdatum: new \DateTimeImmutable('1980-05-17'), eintrittsdatum: new \DateTimeImmutable('2010-01-01'), abteilungIds: [$badminton, $fussball], beitragsartId: $art, iban: 'DE02120300000000202051'),
            new Mitglied(1002, 'Beispiel', vorname: 'Karl', abteilungIds: []),
            new Mitglied(1003, 'Altmann', vorname: 'Hans', geschlecht: Geschlecht::M, geburtsdatum: new \DateTimeImmutable('1950-01-01'), austrittsdatum: new \DateTimeImmutable('2020-01-31'), abteilungIds: [$fussball]),
        ] as $mitglied) {
            $repo->speichern($mitglied, VersionsAnlass::angelegt(), 1);
        }
    }

    /** @param array<string, string> $felder */
    private function exportieren(string $liste, array $felder): string
    {
        $session = new Session();
        $session->start();
        $felder['_csrf'] = $session->csrfToken();
        $antwort = $this->kernel->handle(new Request(HttpMethod::Post, '/app/listen/' . $liste . '/export', post: $felder, ip: '203.0.113.7'));
        self::assertInstanceOf(StreamResponse::class, $antwort);

        return implode('', iterator_to_array($antwort->chunks, false));
    }

    /** @return list<list<string>> */
    private static function csv(string $inhalt): array
    {
        $zeilen = [];
        foreach (explode("\r\n", rtrim(substr($inhalt, 3), "\r\n")) as $zeile) {
            $zeilen[] = explode(';', $zeile);
        }

        return $zeilen;
    }

    public function testBlsvHasExactlyTheColumnsOfTheTemplateInOrder(): void
    {
        $this->als(Rolle::Vorstand);

        $zeilen = self::csv($this->exportieren('blsv', ['format' => 'csv', 'stichtag' => '2027-01-01']));

        self::assertSame(['Titel', 'Name', 'Vorname', 'Namenszusatz', 'Geschlecht', 'Geburtsdatum', 'Spartenkennzeichen'], $zeilen[0]);
    }

    public function testBlsvHasOneRowPerMemberAndDepartmentAndKeepsMembersWithoutOne(): void
    {
        $this->als(Rolle::Admin);

        $zeilen = self::csv($this->exportieren('blsv', ['format' => 'csv', 'stichtag' => '2027-01-01']));

        // former member Altmann is left out; Beispiel stays without a department
        self::assertSame([
            ['', 'Beispiel', 'Karl', '', '', '', ''],
            ['Dr.', 'Mustermann', 'Erika', 'von', 'w', '17.05.1980', '01'],
            ['Dr.', 'Mustermann', 'Erika', 'von', 'w', '17.05.1980', '09'],
        ], array_slice($zeilen, 1));
    }

    public function testBlsvMembersWhoEnteredAfterTheCutOffDayAreLeftOut(): void
    {
        $this->als(Rolle::Admin);

        $zeilen = self::csv($this->exportieren('blsv', ['format' => 'csv', 'stichtag' => '2005-01-01']));

        // Mustermann entered in 2010 and is not yet a member; Altmann (still a member then) and Beispiel stay
        self::assertSame(['Altmann', 'Beispiel'], array_column(array_slice($zeilen, 1), 1));
    }

    public function testBlsvHintsNameMembersWithoutGenderOrBirthdateAndShowTheClubNumber(): void
    {
        $this->als(Rolle::Admin);
        new VereinRepository($this->pdo, $this->dienste->einstellungen())->verbaendeErsetzen([
            ['id' => 0, 'name' => 'BLSV', 'vereinsnummer' => '123456', 'bemerkung' => ''],
        ]);

        $seite = $this->get('/app/listen/blsv', ['stichtag' => '2027-01-01'])->body;

        self::assertStringContainsString('123456', $seite);
        self::assertStringContainsString('Ohne Geschlecht (Mitgliedsnummern): 1002', $seite);
        self::assertStringContainsString('Ohne Geburtsdatum (Mitgliedsnummern): 1002', $seite);
    }

    public function testBlsvXlsxHoldsBirthdateAsDateCellAndSpartenkennzeichenAsText(): void
    {
        $this->als(Rolle::Admin);
        $datei = tempnam(sys_get_temp_dir(), 'mv-blsv-');
        self::assertIsString($datei);
        try {
            file_put_contents($datei, $this->exportieren('blsv', ['format' => 'xlsx', 'stichtag' => '2027-01-01']));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($datei, \ZipArchive::CHECKCONS));
            $blatt = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            self::assertInstanceOf(\SimpleXMLElement::class, $blatt);
            $blatt->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $zellen = $blatt->xpath('//m:row[@r="3"]/m:c');
            self::assertIsArray($zellen);
            self::assertSame('2', (string) $zellen[5]['s'], 'birthdate is a date cell');
            self::assertSame('01', (string) $zellen[6]->is->t, 'the leading zero of the code stays');
        } finally {
            @unlink($datei);
        }
    }

    public function testGesamtexportHasAllFieldsAndTheAdminAlsoGetsFeeColumns(): void
    {
        $this->als(Rolle::Admin);

        $zeilen = self::csv($this->exportieren('alle-mitglieder', ['format' => 'csv']));

        self::assertContains('Beitragsart', $zeilen[0]);
        self::assertContains('IBAN', $zeilen[0]);
        self::assertContains('Status Jahresbeitrag ' . date('Y'), $zeilen[0]);
        $mustermann = array_values(array_filter($zeilen, static fn(array $z): bool => $z[0] === '1001'))[0];
        self::assertContains('Badminton, Fußball', $mustermann);
        self::assertContains('Aktiv', $mustermann, 'fee type by name');
        self::assertCount(2 + 1, $zeilen, 'two current members, former ones excluded');
    }

    public function testGesamtexportTheBoardGetsTheFeeColumns(): void
    {
        $this->als(Rolle::Vorstand);

        $csv = $this->exportieren('alle-mitglieder', ['format' => 'csv']);
        $kopf = self::csv($csv)[0];

        foreach (['Beitragsart', 'Zahlart', 'IBAN', 'BIC', 'Kontoinhaber', 'Mandatsreferenz', 'Mandatsdatum'] as $spalte) {
            self::assertContains($spalte, $kopf);
        }
        self::assertStringContainsString('DE02120300000000202051', $csv);
        self::assertContains('Mitgliedsnummer', $kopf);
    }

    public function testGesamtexportCanIncludeFormerMembers(): void
    {
        $this->als(Rolle::Admin);

        $zeilen = self::csv($this->exportieren('alle-mitglieder', ['format' => 'csv', 'ehemalige' => '1']));

        self::assertCount(3 + 1, $zeilen);
    }

    public function testBothExportsWriteAnAuditEntryWithoutMemberData(): void
    {
        $this->als(Rolle::Admin);
        $audit = $this->dienste->audit();

        $this->exportieren('blsv', ['format' => 'csv']);
        $this->exportieren('alle-mitglieder', ['format' => 'xlsx']);

        self::assertSame(2, $audit->anzahl());
        foreach ($audit->neueste(2) as $eintrag) {
            self::assertSame('liste', $eintrag->entity);
            self::assertStringNotContainsString('Mustermann', $eintrag->details);
        }
    }
}
