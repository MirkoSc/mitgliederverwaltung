<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlweise;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Service\Beitraege\Einzuege;
use App\Service\Import\ErstImport;
use App\Service\Storage\BlobService;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;
use App\Tests\Service\Beitraege\Pain008Test;
use App\Tests\Support\AppTestCase;

/**
 * "Beiträge <Jahr> löschen" and "Ausgewählte als bezahlt markieren" through
 * the whole application (docs/spec/08-beitraege.md section 2a, #90). Every
 * name and IBAN is made up.
 */
final class BeitragsjahrSeiteTest extends AppTestCase
{
    private int $nummer = 0;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dienste->einstellungen()->set(BlobService::SETTING_BACKEND, 'db');
    }

    private function beitraege(): JahresbeitragRepository
    {
        return new JahresbeitragRepository($this->dienste->pdo());
    }

    private function beitrag(string $nachname, int $jahr = 2026): int
    {
        $mitglied = new MitgliedRepository($this->dienste->pdo())->speichern(
            new Mitglied(++$this->nummer, $nachname, vorname: 'Erika', eintrittsdatum: new \DateTimeImmutable('2015-03-01'), iban: 'DE02120300000000202051'),
            VersionsAnlass::angelegt(),
            null,
        )->mitglied;

        return (int) $this->beitraege()->anlegenWennFehlt(new Jahresbeitrag((int) $mitglied->id, $jahr, 8500))?->id;
    }

    private function flash(): string
    {
        return (string) $this->dienste->session->pullFlash()?->text;
    }

    /**
     * @param array<string, string|list<string>> $felder
     */
    private function senden(string $pfad, array $felder): Response
    {
        $session = new Session();
        $session->start();

        return $this->aufrufen(new Request(HttpMethod::Post, $pfad, post: ['_csrf' => $session->csrfToken(), ...$felder]));
    }

    /** The list "Beiträge <Jahr>" only - the direct debit on the same page has checkboxes of its own. */
    private static function liste(string $seite): string
    {
        $start = strpos($seite, 'id="liste-titel"');
        self::assertNotFalse($start);
        $ende = strpos($seite, 'id="einzug-archiv', $start);

        return substr($seite, $start, $ende === false ? null : $ende - $start);
    }

    private function audit(string $aktion): int
    {
        $stmt = $this->dienste->pdo()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
        $stmt->execute([$aktion]);

        return (int) $stmt->fetchColumn();
    }

    public function testTheVorstandGets403OnEveryNewRoute(): void
    {
        $beitrag = $this->beitrag('Musterfrau');
        $this->als(Rolle::Vorstand);

        self::assertSame(403, $this->get('/app/beitraege/jahr-loeschen', ['jahr' => '2026'])->status);
        self::assertSame(403, $this->post('/app/beitraege/jahr-loeschen', ['jahr' => '2026', 'bestaetigung' => 'LÖSCHEN'])->status);
        self::assertSame(403, $this->senden('/app/beitraege/bezahlt', ['bezahlt' => [(string) $beitrag], 'zahlweise' => 'bar', 'auswahl_vollstaendig' => '1'])->status);
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege()->finde($beitrag)?->status);
    }

    public function testTheListHasACheckboxForEveryOpenFeeOnly(): void
    {
        $offen = $this->beitrag('Offenbach');
        $bezahlt = $this->beitrag('Zahlmann');
        $this->als(Rolle::Admin);
        $this->post("/app/beitraege/$bezahlt/bezahlt", ['zahlweise' => 'bar']);

        $seite = $this->get('/app/beitraege', ['jahr' => '2026', 'status' => 'alle'])->body;
        $liste = self::liste($seite);
        self::assertStringContainsString('name="bezahlt[]" value="' . $offen . '" form="mehrfach-bezahlt"', $liste);
        self::assertStringNotContainsString('value="' . $bezahlt . '" form="mehrfach-bezahlt"', $liste);
        self::assertStringContainsString('Alle auswählen', $liste);
        self::assertStringContainsString('role="status"', $liste);
        self::assertStringContainsString('Ausgewählte als bezahlt markieren', $liste);
        self::assertStringContainsString('name="auswahl_vollstaendig" value="1" form="mehrfach-bezahlt"', $liste);
        self::assertStringContainsString('/js/beitraege-auswahl.js', $seite);
        self::assertStringContainsString('href="/app/beitraege/jahr-loeschen?jahr=2026"', $seite);

        // Only paid fees in view: no form, no checkbox column.
        $nurBezahlt = self::liste($this->get('/app/beitraege', ['jahr' => '2026', 'status' => 'bezahlt'])->body);
        self::assertStringNotContainsString('mehrfach-bezahlt', $nurBezahlt);
    }

    public function testSeveralFeesArePaidAtOnceAndOnlyTheChosenOnes(): void
    {
        $a = $this->beitrag('Albrecht');
        $b = $this->beitrag('Brandt');
        $c = $this->beitrag('Christ');
        $this->als(Rolle::Admin);

        $antwort = $this->senden('/app/beitraege/bezahlt', [
            'bezahlt' => [(string) $a, (string) $c],
            'zahlweise' => 'ueberweisung',
            'bezahlt_am' => '2026-04-01',
            'vermerk' => 'Kontoauszug 4',
            'zurueck' => '/app/beitraege?jahr=2026',
            'auswahl_vollstaendig' => '1',
        ]);

        self::assertWeiterleitungNach('/app/beitraege?jahr=2026', $antwort);
        self::assertSame('2 Beiträge als bezahlt vermerkt (170,00 €, Überweisung am 01.04.2026).', str_replace("\u{a0}", ' ', $this->flash()));
        foreach ([$a, $c] as $id) {
            $beitrag = $this->beitraege()->finde($id);
            self::assertSame(JahresbeitragStatus::Bezahlt, $beitrag?->status);
            self::assertSame(Zahlweise::Ueberweisung, $beitrag->zahlweise);
        }
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege()->finde($b)?->status);
        self::assertSame(1, $this->audit('beitraege.bezahlt'));
    }

    public function testAListThatArrivedCutChangesNothing(): void
    {
        $a = $this->beitrag('Albrecht');
        $this->als(Rolle::Admin);

        $this->senden('/app/beitraege/bezahlt', ['bezahlt' => [(string) $a], 'zahlweise' => 'bar']);

        self::assertStringContainsString('nicht vollständig', $this->flash());
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege()->finde($a)?->status);
        self::assertSame(0, $this->audit('beitraege.bezahlt'));
    }

    public function testDeletingAYearAsksFirstAndNeedsTheWord(): void
    {
        VereinRepository::aus($this->dienste)->speichern(Vereinsdaten::ausGespeichertem([
            'vereinsname' => 'TSV Beispielstadt 1899 e.V.',
            'iban' => 'DE75512108001245126199',
            'glaeubiger_id' => Pain008Test::GLAEUBIGER_ID,
            'mandatsdatum' => '2016-01-01',
        ]));
        $a = $this->beitrag('Albrecht');
        $this->beitrag('Brandt');
        $vorjahr = $this->beitrag('Christ', 2025);
        $einzug = Einzuege::aus($this->dienste)->erzeugen([$a], new \DateTimeImmutable('2026-03-02'), null);
        Einzuege::aus($this->dienste)->bestaetigen((int) $einzug->id, null);
        $this->als(Rolle::Admin);
        self::assertFalse(ErstImport::aus($this->dienste)->kannLeeren());

        $frage = $this->get('/app/beitraege/jahr-loeschen', ['jahr' => '2026']);
        self::assertSame(200, $frage->status);
        self::assertStringContainsString('Beiträge 2026 löschen?', $frage->body);
        self::assertStringContainsString('2 Jahresbeiträge', $frage->body);
        self::assertStringContainsString('davon 1 bezahlt', $frage->body);
        self::assertStringContainsString('1 Einzug', $frage->body);
        self::assertStringContainsString('Nr. ' . $einzug->id, $frage->body);
        self::assertStringContainsString('Die Bank hat die Datei eventuell schon erhalten.', $frage->body);
        self::assertStringContainsString('name="bestaetigung"', $frage->body);

        $falsch = $this->post('/app/beitraege/jahr-loeschen', ['jahr' => '2026', 'bestaetigung' => 'ja']);
        self::assertWeiterleitungNach('/app/beitraege/jahr-loeschen?jahr=2026', $falsch);
        self::assertStringContainsString('Es wurde nichts gelöscht', $this->flash());
        self::assertCount(2, $this->beitraege()->imJahr(2026));

        $richtig = $this->post('/app/beitraege/jahr-loeschen', ['jahr' => '2026', 'bestaetigung' => 'löschen']);
        self::assertWeiterleitungNach('/app/beitraege?jahr=2026', $richtig);
        self::assertStringStartsWith('Die Beiträge 2026 sind gelöscht: 2 Jahresbeiträge (davon 1 bezahlt', $this->flash());
        self::assertSame([], $this->beitraege()->imJahr(2026));
        self::assertNotNull($this->beitraege()->finde($vorjahr));
        self::assertSame(1, $this->audit('beitraege.jahr_geloescht'));

        // With the last year gone, the stock can be emptied again (10 section 1).
        self::assertFalse(ErstImport::aus($this->dienste)->kannLeeren());
        $this->post('/app/beitraege/jahr-loeschen', ['jahr' => '2025', 'bestaetigung' => 'LÖSCHEN']);
        self::assertTrue(ErstImport::aus($this->dienste)->kannLeeren());
    }

    public function testAYearWithoutFeesHasNothingToDelete(): void
    {
        $this->als(Rolle::Admin);

        self::assertStringContainsString('es gibt nichts zu löschen', $this->get('/app/beitraege/jahr-loeschen', ['jahr' => '2026'])->body);
        self::assertStringNotContainsString('jahr-loeschen', $this->get('/app/beitraege', ['jahr' => '2026'])->body);
        self::assertWeiterleitungNach('/app/beitraege', $this->get('/app/beitraege/jahr-loeschen', ['jahr' => '1999']));
    }
}
