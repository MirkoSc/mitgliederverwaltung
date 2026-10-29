<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Rolle;
use App\Tests\Support\AppTestCase;

/**
 * The settings sections Abteilungen, Funktionen, Beitragsarten, runde
 * Geburtstage and Ehrungsstufen through the whole application.
 */
final class StammdatenTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    /** @return array<string, mixed> */
    private function zeile(string $id, string $name, array $mehr = []): array
    {
        return ['id' => $id, 'name' => $name, 'position' => '1', 'aktiv' => '1'] + $mehr;
    }

    private function wert(string $sql): mixed
    {
        return $this->dienste->pdo()->query($sql)->fetchColumn();
    }

    public function testAdminSeesAllFiveSectionsVorstandNone(): void
    {
        $this->als(Rolle::Admin);
        $seite = $this->get('/admin')->body;
        foreach (['abteilungen', 'funktionen', 'beitragsarten', 'geburtstage', 'ehrungsstufen'] as $schluessel) {
            self::assertStringContainsString('id="titel-' . $schluessel . '"', $seite, $schluessel);
        }

        $this->als(Rolle::Vorstand);
        self::assertSame(403, $this->get('/admin')->status);
    }

    public function testVorstandCannotSaveAnything(): void
    {
        $this->als(Rolle::Vorstand);
        $vorher = $this->wert('SELECT COUNT(*) FROM abteilung');

        foreach (['abteilungen', 'funktionen', 'beitragsarten', 'geburtstage', 'ehrungsstufen'] as $pfad) {
            self::assertSame(403, $this->post('/admin/' . $pfad, ['zeile' => [$this->zeile('', 'Neu')], 'werte' => '1'])->status, $pfad);
        }
        self::assertSame($vorher, $this->wert('SELECT COUNT(*) FROM abteilung'));
    }

    public function testDepartmentsCanBeAddedChangedAndDeleted(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->post('/admin/abteilungen', ['zeile' => [$this->zeile('', 'Schach', ['blsv_nummer' => '09'])]]);
        self::assertWeiterleitungNach('/admin#abteilungen', $antwort);
        self::assertSame('09', $this->wert("SELECT blsv_nummer FROM abteilung WHERE name = 'Schach'"));

        $id = (string) $this->wert("SELECT id FROM abteilung WHERE name = 'Schach'");
        $this->post('/admin/abteilungen', ['zeile' => [['id' => $id, 'name' => 'Schach neu', 'position' => '5']]]);
        self::assertSame('0', (string) $this->wert("SELECT aktiv FROM abteilung WHERE id = {$id}"), 'unchecked box = inactive');
        self::assertSame('Schach neu', $this->wert("SELECT name FROM abteilung WHERE id = {$id}"));

        $this->post('/admin/abteilungen', ['zeile' => [['id' => $id, 'name' => 'Schach neu', 'loeschen' => '1']]]);
        self::assertFalse($this->wert("SELECT id FROM abteilung WHERE id = {$id}"));
    }

    public function testADepartmentInUseIsDeactivatedInsteadOfDeleted(): void
    {
        $this->als(Rolle::Admin);
        $pdo = $this->dienste->pdo();
        $abteilung = (int) $this->wert('SELECT id FROM abteilung ORDER BY id LIMIT 1');
        $pdo->exec("INSERT INTO mitglied (mitgliedsnummer, nachname, created_at, updated_at) VALUES (1, 'Muster', NOW(), NOW())");
        $pdo->exec('INSERT INTO mitglied_abteilung (mitglied_id, abteilung_id) VALUES (' . $pdo->lastInsertId() . ", {$abteilung})");

        $this->post('/admin/abteilungen', ['zeile' => [['id' => (string) $abteilung, 'name' => 'Egal', 'loeschen' => '1']]]);

        self::assertSame('0', (string) $this->wert("SELECT aktiv FROM abteilung WHERE id = {$abteilung}"));
        self::assertStringContainsString('nur deaktiviert', $this->get('/admin')->body);
    }

    public function testFunctionsKeepTheLicenceFlag(): void
    {
        $this->als(Rolle::Admin);

        $this->post('/admin/funktionen', ['zeile' => [
            $this->zeile('', 'Trainer', ['mit_lizenz' => '1']),
            $this->zeile('', 'Kassenprüfer'),
            $this->zeile('', ''),
        ]]);

        self::assertSame('1', (string) $this->wert("SELECT mit_lizenz FROM funktion WHERE name = 'Trainer'"));
        self::assertSame('0', (string) $this->wert("SELECT mit_lizenz FROM funktion WHERE name = 'Kassenprüfer'"));
        self::assertSame('0', (string) $this->wert("SELECT COUNT(*) FROM funktion WHERE name = ''"));
    }

    public function testFeeTypesStoreCentRuleAndActiveKind(): void
    {
        $this->als(Rolle::Admin);

        $this->post('/admin/beitragsarten', ['zeile' => [
            $this->zeile('', 'Förderer', ['betrag' => '1.234,5', 'regel' => 'rentner', 'aktiv_art' => 'passiv']),
        ]]);

        $zeile = $this->dienste->pdo()->query("SELECT betrag_cent, regel, aktiv_art FROM beitragsart WHERE name = 'Förderer'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['betrag_cent' => 123450, 'regel' => 'rentner', 'aktiv_art' => 'passiv'], $zeile);
        self::assertStringContainsString('value="1234,50"', $this->get('/admin')->body);
    }

    public function testAnInvalidAmountIsAHintAndKeepsTheOldAmount(): void
    {
        $this->als(Rolle::Admin);
        $id = (string) $this->wert("SELECT id FROM beitragsart WHERE name = 'Aktiv'");
        $alt = $this->wert("SELECT betrag_cent FROM beitragsart WHERE id = {$id}");

        $this->post('/admin/beitragsarten', ['zeile' => [$this->zeile($id, 'Aktiv', ['betrag' => 'viel', 'regel' => '', 'aktiv_art' => 'aktiv'])]]);

        self::assertSame($alt, $this->wert("SELECT betrag_cent FROM beitragsart WHERE id = {$id}"));
        self::assertStringContainsString('keine gültige Zahl', $this->get('/admin')->body);
    }

    public function testListsOfNumbersAreSortedDeduplicatedAndWarnAboutJunk(): void
    {
        $this->als(Rolle::Admin);

        $this->post('/admin/geburtstage', ['werte' => '50, 18 30;18 abc 0 999']);
        $this->post('/admin/ehrungsstufen', ['werte' => '40 25']);

        $settings = new \App\Repository\SettingRepository($this->dienste->pdo());
        self::assertSame([18, 30, 50], $settings->get('runde_geburtstage'));
        self::assertSame([25, 40], $settings->get('ehrungsstufen'));
        // The flash of the first request was consumed by the second; check the form instead.
        self::assertStringContainsString('value="18, 30, 50"', $this->get('/admin')->body);
    }

    public function testJunkInANumberListIsReported(): void
    {
        $this->als(Rolle::Admin);

        $this->post('/admin/geburtstage', ['werte' => '18 abc']);

        self::assertStringContainsString('Nicht übernommen', $this->get('/admin')->body);
    }
}
