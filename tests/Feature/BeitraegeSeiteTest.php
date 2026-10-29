<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlweise;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The page "Beiträge", the page of one fee and the member tab "Beiträge"
 * (docs/spec/08-beitraege.md section 2, issue #29) through the whole
 * application. Every name is made up.
 */
final class BeitraegeSeiteTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    private function beitraege(): JahresbeitragRepository
    {
        return new JahresbeitragRepository($this->dienste->pdo());
    }

    private function mitglied(int $nummer, string $nachname, string $art = 'Aktiv'): int
    {
        $artId = null;
        foreach (new BeitragsartRepository($this->dienste->pdo())->alle() as $kandidat) {
            if ($kandidat->name === $art) {
                $artId = $kandidat->id;
            }
        }

        return (int) new MitgliedRepository($this->dienste->pdo())->speichern(
            new Mitglied($nummer, $nachname, vorname: 'Erika', beitragsartId: $artId),
            VersionsAnlass::angelegt(),
            null,
        )->mitglied->id;
    }

    private function beitrag(int $mitgliedId, int $jahr = 2026, int $cent = 8500): int
    {
        return (int) $this->beitraege()->anlegenWennFehlt(new Jahresbeitrag($mitgliedId, $jahr, $cent))?->id;
    }

    private function flash(): string
    {
        return (string) $this->dienste->session->pullFlash()?->text;
    }

    public function testTheVorstandGets403OnEveryRouteAndNoTab(): void
    {
        $id = $this->mitglied(1, 'Musterfrau');
        $beitrag = $this->beitrag($id);
        $this->als(Rolle::Vorstand);

        foreach (['/app/beitraege', '/app/beitraege/' . $beitrag] as $pfad) {
            self::assertSame(403, $this->get($pfad)->status, $pfad);
        }
        foreach (['/app/beitraege/erzeugen', "/app/beitraege/$beitrag/betrag", "/app/beitraege/$beitrag/bezahlt", "/app/beitraege/$beitrag/ruecklastschrift", "/app/beitraege/$beitrag/vermerk"] as $pfad) {
            self::assertSame(403, $this->post($pfad, ['jahr' => '2026', 'betrag' => '1', 'zahlweise' => 'bar', 'vermerk' => 'x'])->status, $pfad);
        }
        self::assertSame(8500, $this->beitraege()->finde($beitrag)?->betragCent);
        self::assertSame(JahresbeitragStatus::Offen, $this->beitraege()->finde($beitrag)?->status);
        self::assertSame([], $this->beitraege()->finde($beitrag)?->vermerke);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $id, 'reiter' => 'beitraege'])->body;
        self::assertStringNotContainsString('reiter=beitraege', $seite, 'no tab for the Vorstand');
        self::assertStringNotContainsString('mitglied-beitraege', $seite, 'the Daten tab instead');
        self::assertStringNotContainsString('/app/beitraege/', $seite);
    }

    public function testTheAdminCreatesTheYearsFeesFromThePage(): void
    {
        $this->mitglied(1, 'Musterfrau');
        $this->mitglied(2, 'Beispiel', 'Beitragsfrei');
        $this->als(Rolle::Admin);

        $seite = $this->get('/app/beitraege', ['jahr' => '2026']);
        self::assertSame(200, $seite->status);
        self::assertStringContainsString('Beiträge für 2026 erzeugen', $seite->body);
        self::assertStringContainsString('Noch keine Beiträge für 2026', $seite->body);

        $antwort = $this->post('/app/beitraege/erzeugen', ['jahr' => '2026']);
        self::assertWeiterleitungNach('/app/beitraege?jahr=2026', $antwort);
        self::assertSame('1 Jahresbeitrag für 2026 erzeugt.', $this->flash());

        $this->post('/app/beitraege/erzeugen', ['jahr' => '2026']);
        self::assertSame('Für 2026 gab es keine fehlenden Jahresbeiträge. 1 Mitglied hatte seinen schon.', $this->flash());
        self::assertCount(1, $this->beitraege()->imJahr(2026));

        $seite = $this->get('/app/beitraege', ['jahr' => '2026'])->body;
        self::assertStringContainsString('Musterfrau, Erika', $seite);
        self::assertStringContainsString('85,00', $seite);
        self::assertStringNotContainsString('Beispiel', $seite);
        self::assertStringContainsString('Heute bezahlt', $seite);
    }

    public function testAYearOutOfRangeIsNotCreated(): void
    {
        $this->mitglied(1, 'Musterfrau');
        $this->als(Rolle::Admin);

        foreach (['1999', '2200', 'abc', ''] as $jahr) {
            self::assertWeiterleitungNach('/app/beitraege', $this->post('/app/beitraege/erzeugen', ['jahr' => $jahr]));
        }
        self::assertSame(0, (int) $this->dienste->pdo()->query('SELECT COUNT(*) FROM jahresbeitrag')->fetchColumn());
    }

    public function testTheListFiltersByStatusOpenByDefault(): void
    {
        $offen = $this->beitrag($this->mitglied(1, 'Offenbach'));
        $bezahlt = $this->beitrag($this->mitglied(2, 'Zahlmann'));
        $this->als(Rolle::Admin);
        $this->post("/app/beitraege/$bezahlt/bezahlt", ['zahlweise' => 'bar']);

        $standard = self::liste($this->get('/app/beitraege', ['jahr' => '2026'])->body);
        self::assertStringContainsString('Offenbach', $standard);
        self::assertStringNotContainsString('Zahlmann', $standard);

        $alle = self::liste($this->get('/app/beitraege', ['jahr' => '2026', 'status' => 'alle'])->body);
        self::assertStringContainsString('Offenbach', $alle);
        self::assertStringContainsString('Zahlmann', $alle);
        self::assertStringContainsString('/app/beitraege/' . $offen . '?zurueck=', $alle);

        $nurBezahlt = self::liste($this->get('/app/beitraege', ['jahr' => '2026', 'status' => 'bezahlt'])->body);
        self::assertStringNotContainsString('Offenbach', $nurBezahlt);
    }

    /** Only the year's list - the direct debit on the same page (#30) shows open fees of its own. */
    private static function liste(string $seite): string
    {
        $anfang = strpos($seite, '<section class="beitraege-liste"');
        self::assertNotFalse($anfang);
        $ende = strpos($seite, '</section>', $anfang);
        self::assertNotFalse($ende);

        return substr($seite, $anfang, $ende - $anfang);
    }

    public function testPaidQuicklyFromTheListReturnsToTheList(): void
    {
        $beitrag = $this->beitrag($this->mitglied(1, 'Musterfrau'));
        $this->als(Rolle::Admin);

        $antwort = $this->post("/app/beitraege/$beitrag/bezahlt", ['zahlweise' => 'ueberweisung', 'zurueck' => '/app/beitraege?jahr=2026']);

        self::assertWeiterleitungNach('/app/beitraege?jahr=2026', $antwort);
        $b = $this->beitraege()->finde($beitrag);
        self::assertSame(JahresbeitragStatus::Bezahlt, $b?->status);
        self::assertSame(Zahlweise::Ueberweisung, $b->zahlweise);
        self::assertSame(new \DateTimeImmutable('today')->format('Y-m-d'), $b->bezahltAm?->format('Y-m-d'));
    }

    public function testTheFeePageChangesAmountOnlyWhileOpen(): void
    {
        $beitrag = $this->beitrag($this->mitglied(1, 'Musterfrau'));
        $konto = $this->als(Rolle::Admin);

        $seite = $this->get('/app/beitraege/' . $beitrag)->body;
        self::assertStringContainsString('Jahresbeitrag 2026', $seite);
        self::assertStringContainsString('Betrag ändern', $seite);
        self::assertStringContainsString('Als bezahlt vermerken', $seite);
        self::assertStringNotContainsString('Zurück auf offen setzen', $seite);

        $antwort = $this->post("/app/beitraege/$beitrag/betrag", ['betrag' => '42,50', 'grund' => 'Härtefall']);
        self::assertWeiterleitungNach('/app/beitraege/' . $beitrag, $antwort);
        self::assertSame('Betrag geändert.', $this->flash());
        $b = $this->beitraege()->finde($beitrag);
        self::assertSame(4250, $b?->betragCent);
        self::assertSame($konto->id, $b->vermerke[0]->von);

        $this->post("/app/beitraege/$beitrag/betrag", ['betrag' => 'viel']);
        self::assertStringContainsString('keine Zahl', $this->flash());

        $this->post("/app/beitraege/$beitrag/bezahlt", ['zahlweise' => 'bar', 'bezahlt_am' => '2026-02-03', 'vermerk' => 'im Training']);
        $this->post("/app/beitraege/$beitrag/betrag", ['betrag' => '10,00']);
        self::assertSame('Der Betrag lässt sich nur ändern, solange der Beitrag offen ist.', $this->flash());
        self::assertSame(4250, $this->beitraege()->finde($beitrag)?->betragCent);

        $seite = $this->get('/app/beitraege/' . $beitrag)->body;
        self::assertStringNotContainsString('Betrag ändern', $seite);
        self::assertStringContainsString('Zurück auf offen setzen', $seite);
        self::assertStringContainsString('03.02.2026', $seite);
        self::assertStringContainsString('im Training', $seite);
    }

    public function testAReturnedDebitNeedsANoteAndReopensTheFee(): void
    {
        $beitrag = $this->beitrag($this->mitglied(1, 'Musterfrau'));
        $this->als(Rolle::Admin);
        $this->post("/app/beitraege/$beitrag/bezahlt", ['zahlweise' => 'bar']);

        $this->post("/app/beitraege/$beitrag/ruecklastschrift", ['vermerk' => '']);
        self::assertStringContainsString('warum', $this->flash());
        self::assertSame(JahresbeitragStatus::Bezahlt, $this->beitraege()->finde($beitrag)?->status);

        $this->post("/app/beitraege/$beitrag/ruecklastschrift", ['vermerk' => 'Bank meldet Rücklastschrift']);
        self::assertSame('Der Beitrag ist wieder offen.', $this->flash());
        $b = $this->beitraege()->finde($beitrag);
        self::assertSame(JahresbeitragStatus::Offen, $b?->status);
        self::assertStringContainsString('Bank meldet Rücklastschrift', $b->vermerke[1]->text);
    }

    public function testAFeeInADirectDebitIsNotPaidByHand(): void
    {
        $beitrag = $this->beitrag($this->mitglied(1, 'Musterfrau'));
        $repo = $this->beitraege();
        $repo->speichern(($repo->finde($beitrag) ?? self::fail())->mit(['status' => JahresbeitragStatus::ImEinzug]));
        $this->als(Rolle::Admin);

        $this->post("/app/beitraege/$beitrag/bezahlt", ['zahlweise' => 'bar']);

        self::assertStringContainsString('steckt in einem Einzug', $this->flash());
        self::assertSame(JahresbeitragStatus::ImEinzug, $repo->finde($beitrag)?->status);
        self::assertStringContainsString('bestätigen Sie den Einzug', $this->get('/app/beitraege/' . $beitrag)->body);
    }

    public function testANoteOfItsOwnAndAnInvalidDate(): void
    {
        $beitrag = $this->beitrag($this->mitglied(1, 'Musterfrau'));
        $this->als(Rolle::Admin);

        $this->post("/app/beitraege/$beitrag/vermerk", ['vermerk' => 'Mahnung per Post']);
        self::assertSame('Vermerk gespeichert.', $this->flash());
        $this->post("/app/beitraege/$beitrag/vermerk", ['vermerk' => '   ']);
        self::assertSame('Der Vermerk ist leer.', $this->flash());
        $this->post("/app/beitraege/$beitrag/bezahlt", ['zahlweise' => 'bar', 'bezahlt_am' => '31.02.2026']);
        self::assertStringContainsString('Datum ist ungültig', $this->flash());
        $this->post("/app/beitraege/$beitrag/bezahlt", ['zahlweise' => 'scheck']);
        self::assertStringContainsString('wie bezahlt wurde', $this->flash());

        $b = $this->beitraege()->finde($beitrag);
        self::assertSame(JahresbeitragStatus::Offen, $b?->status);
        self::assertSame(['Mahnung per Post'], array_map(static fn($v) => $v->text, $b->vermerke));
    }

    public function testActionsOnlyReturnToOwnPages(): void
    {
        $beitrag = $this->beitrag($this->mitglied(1, 'Musterfrau'));
        $this->als(Rolle::Admin);

        foreach (['https://example.org/', '//example.org/app/beitraege', '/admin/benutzer', "/app/beitraege\nX"] as $fremd) {
            self::assertWeiterleitungNach('/app/beitraege/' . $beitrag, $this->post("/app/beitraege/$beitrag/vermerk", ['vermerk' => 'x', 'zurueck' => $fremd]));
        }
        self::assertWeiterleitungNach(
            '/app/mitglieder?mitglied=1&reiter=beitraege',
            $this->post("/app/beitraege/$beitrag/vermerk", ['vermerk' => 'x', 'zurueck' => '/app/mitglieder?mitglied=1&reiter=beitraege']),
        );
        self::assertStringNotContainsString('example.org', $this->get('/app/beitraege/' . $beitrag, ['zurueck' => 'https://example.org/'])->body);
    }

    public function testAnUnknownFeeIs404(): void
    {
        $this->als(Rolle::Admin);

        self::assertSame(404, $this->get('/app/beitraege/999999')->status);
        self::assertSame(404, $this->post('/app/beitraege/999999/vermerk', ['vermerk' => 'x'])->status);
    }

    public function testTheMemberTabShowsAllYearsWithStatusAndNotes(): void
    {
        $id = $this->mitglied(1, 'Musterfrau');
        $alt = $this->beitrag($id, 2025, 8000);
        $this->beitrag($id, 2026);
        $this->als(Rolle::Admin);
        $this->post("/app/beitraege/$alt/bezahlt", ['zahlweise' => 'bar', 'bezahlt_am' => '2025-03-01', 'vermerk' => 'beim Sommerfest']);

        $seite = $this->get('/app/mitglieder', ['mitglied' => (string) $id, 'reiter' => 'beitraege']);

        self::assertSame(200, $seite->status);
        $html = $seite->body;
        self::assertStringContainsString('reiter=beitraege" aria-current="page"', $html);
        self::assertLessThan(strpos($html, '>2025<'), strpos($html, '>2026<'), 'newest first');
        self::assertStringContainsString('80,00', $html);
        self::assertStringContainsString('beim Sommerfest', $html);
        self::assertStringContainsString('01.03.2025', $html);
        self::assertStringContainsString('/app/beitraege/' . $alt . '?zurueck=' . rawurlencode('/app/mitglieder?mitglied=' . $id . '&reiter=beitraege'), $html);

        $leer = $this->get('/app/mitglieder', ['mitglied' => (string) $this->mitglied(2, 'Neumann'), 'reiter' => 'beitraege'])->body;
        self::assertStringContainsString('Noch keine Jahresbeiträge', $leer);
    }

    public function testTheFeePageLeadsBackWhereItCameFrom(): void
    {
        $id = $this->mitglied(1, 'Musterfrau');
        $beitrag = $this->beitrag($id);
        $this->als(Rolle::Admin);

        $vomMitglied = $this->get('/app/beitraege/' . $beitrag, ['zurueck' => '/app/mitglieder?mitglied=' . $id . '&reiter=beitraege'])->body;
        self::assertStringContainsString('Zurück zum Mitglied', $vomMitglied);
        self::assertStringContainsString('name="zurueck" value="/app/beitraege/' . $beitrag . '?zurueck=', $vomMitglied);

        $direkt = $this->get('/app/beitraege/' . $beitrag)->body;
        self::assertStringContainsString('Zurück zu den Beiträgen', $direkt);
        self::assertStringContainsString('href="/app/beitraege?jahr=2026"', $direkt);
    }
}
