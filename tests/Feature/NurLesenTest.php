<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\Vermerk;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlart;
use App\Domain\Zahlweise;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Tests\Support\AppTestCase;

/**
 * The role "Nur lesen" end to end (issue #87, docs/spec/02-sicherheit.md
 * section 6): it sees members, lists and whether a member has paid, and
 * nowhere a form or button that changes something - and no bank details
 * of members (IBAN, BIC, Kontoinhaber, mandate), on no way: tab, history,
 * search, lists, exports, hints. Made-up members only.
 */
final class NurLesenTest extends AppTestCase
{
    private int $mitgliedId;

    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->dienste->pdo();
        $art = new BeitragsartRepository($pdo)->alle()[0] ?? null;
        $this->mitgliedId = (int) new MitgliedRepository($pdo)->speichern(
            new Mitglied(
                17,
                'Beispiel',
                vorname: 'Erika',
                ort: 'Musterstadt',
                zahlart: Zahlart::Lastschrift,
                iban: 'DE02120300000000202051',
                bic: 'BYLADEM1001',
                kontoinhaber: 'Konrad Kontoinhaber',
                mandatsreferenz: 'MANDAT-4711',
                beitragsartId: $art?->id,
                bemerkung: "Erste Zeile\nZweite Zeile",
                eintrittsdatum: new \DateTimeImmutable(((int) date('Y') - 25) . '-03-01'),
            ),
            VersionsAnlass::angelegt(),
            null,
        )->mitglied->id;

        $beitraege = new JahresbeitragRepository($pdo);
        $beitrag = $beitraege->anlegenWennFehlt(new Jahresbeitrag($this->mitgliedId, (int) date('Y'), 8500));
        self::assertNotNull($beitrag);
        $beitraege->speichern($beitrag->bezahltVonHand(Zahlweise::Bar, new \DateTimeImmutable('today'), new Vermerk(null, new \DateTimeImmutable(), 'bar kassiert')));
        new SettingRepository($pdo)->set('ehrungsstufen', [25]);
    }

    private function mitgliedSeite(?string $reiter = null): string
    {
        return $this->get('/app/mitglieder', ['mitglied' => (string) $this->mitgliedId] + ($reiter === null ? [] : ['reiter' => $reiter]))->body;
    }

    public function testTheMemberOpensWithoutAnyEditingElement(): void
    {
        $this->als(Rolle::Lesend);

        $seite = $this->mitgliedSeite();

        self::assertStringContainsString('Beispiel', $seite);
        self::assertStringContainsString('Musterstadt', $seite);
        self::assertStringContainsString('Sie können dieses Mitglied ansehen, aber nicht ändern.', $seite);
        self::assertStringContainsString('Lastschrift', $seite, 'the Zahlart stays');
        self::assertStringNotContainsString('id="mitglied-formular"', $seite);
        self::assertStringNotContainsString('Mitglied speichern', $seite);
        self::assertStringNotContainsString('Mitglied löschen', $seite);
        self::assertStringNotContainsString('Neues Mitglied', $seite);
        self::assertDoesNotMatchRegularExpression('#<form[^>]+action="/app/mitglieder/\d#', $seite, 'no form posts to a member');
    }

    public function testNoTabOffersAChange(): void
    {
        $this->als(Rolle::Lesend);

        foreach (['familie', 'nachweise', 'verlauf', 'beitraege'] as $reiter) {
            $seite = $this->mitgliedSeite($reiter);
            self::assertDoesNotMatchRegularExpression('#<form[^>]+action="/app/(mitglieder/\d|beitraege)#', $seite, $reiter);
            self::assertStringNotContainsString('Familie wählen oder anlegen', $seite, $reiter);
            self::assertStringNotContainsString('Nachweis vermerken', $seite, $reiter);
            self::assertStringNotContainsString('Diese Version zurückholen', $seite, $reiter);
        }
    }

    /** The bank details of a member, in every spelling the pages use. */
    private const array BANKDATEN = [
        'DE02120300000000202051', 'DE02 1203 0000 0000 2020 51', '202051', 'BYLADEM1001', 'Deutsche Kreditbank',
        'Konrad Kontoinhaber', 'MANDAT-4711', '>IBAN<', '>BIC<', '>Kontoinhaber<', '>Mandatsreferenz<', '>Mandatsdatum<',
    ];

    private static function assertOhneBankdaten(string $html, string $wo): void
    {
        foreach (self::BANKDATEN as $text) {
            self::assertStringNotContainsString($text, $html, $wo . ' shows ' . $text);
        }
    }

    /** No bank details in any tab - the history included, where they were changed. */
    public function testNoTabShowsBankDetails(): void
    {
        $repo = new MitgliedRepository($this->dienste->pdo());
        $mitglied = $repo->finde($this->mitgliedId);
        self::assertNotNull($mitglied);
        $repo->speichern($mitglied->mit(['iban' => 'DE89370400440532013000', 'bic' => '', 'kontoinhaber' => 'Klara Kontowechsel', 'ort' => 'Neustadt']), VersionsAnlass::geaendert(), null);
        $this->als(Rolle::Lesend);

        foreach (['daten', 'familie', 'nachweise', 'verlauf', 'beitraege'] as $reiter) {
            $seite = $this->mitgliedSeite($reiter);
            self::assertOhneBankdaten($seite, $reiter);
            self::assertStringNotContainsString('DE89', $seite, $reiter);
        }
        $verlauf = $this->mitgliedSeite('verlauf');
        self::assertStringContainsString('Neustadt', $verlauf, 'other changes are listed');
        self::assertStringNotContainsString('Klara Kontowechsel', $verlauf, 'the new Kontoinhaber neither');

        // The Vorstand sees them (bankdaten.sehen).
        $this->als(Rolle::Vorstand);
        self::assertStringContainsString('DE89370400440532013000', $this->mitgliedSeite('verlauf'));
    }

    /** The search does not look at IBAN or Kontoinhaber - a hit would tell a piece of them. */
    public function testTheSearchDoesNotRevealBankDetails(): void
    {
        $this->als(Rolle::Lesend);

        foreach (['DE02120300000000202051', '202051', 'Kontoinhaber', 'Konrad'] as $wort) {
            $seite = $this->post('/app/mitglieder', ['text' => $wort])->body;
            self::assertStringContainsString('Kein Mitglied gefunden.', $seite, $wort . ' found the member');
        }
        // The extended IBAN field is ignored: a fitting and a wrong IBAN give the same result.
        foreach (['DE0212030000', 'DE00999999'] as $iban) {
            self::assertStringContainsString('1 Mitglied gefunden.', $this->post('/app/mitglieder', ['iban' => $iban])->body, $iban);
        }
        $seite = $this->post('/app/mitglieder', ['text' => 'Musterstadt'])->body;
        self::assertStringContainsString('1 Mitglied gefunden.', $seite, 'other fields are still searched');
        self::assertStringNotContainsString('IBAN', $seite, 'neither the hint nor the extended search names it');

        $this->als(Rolle::Vorstand);
        self::assertStringContainsString('1 Mitglied gefunden.', $this->post('/app/mitglieder', ['text' => '202051'])->body, 'the Vorstand finds by IBAN');
    }

    /** Lists: the Gesamtexport page without bank columns; the hints name no BIC. */
    public function testListsAndHintsShowNoBankDetails(): void
    {
        $repo = new MitgliedRepository($this->dienste->pdo());
        $mitglied = $repo->finde($this->mitgliedId);
        self::assertNotNull($mitglied);
        // A BIC that does not fit the IBAN: the hint would name the right one.
        $repo->speichern($mitglied->mit(['bic' => 'COBADEFFXXX']), VersionsAnlass::geaendert(), null);
        $this->als(Rolle::Lesend);

        $alle = $this->get('/app/listen/alle-mitglieder')->body;
        self::assertStringContainsString('Zahlart', $alle);
        self::assertOhneBankdaten($alle, 'Alle Mitglieder');

        $auffaellig = $this->get('/app/listen/auffaelligkeiten')->body;
        self::assertStringContainsString('Lastschrift mit falscher BIC.', $auffaellig);
        self::assertStringNotContainsString('COBADEFFXXX', $auffaellig);
        self::assertStringNotContainsString('BYLADEM1001', $auffaellig, 'the BIC that would fit');
        $seite = $this->mitgliedSeite();
        self::assertStringContainsString('Lastschrift mit falscher BIC.', $seite);
        self::assertStringNotContainsString('COBADEFFXXX', $seite);

        $this->als(Rolle::Vorstand);
        self::assertStringContainsString('BYLADEM1001', $this->get('/app/listen/auffaelligkeiten')->body, 'the Vorstand gets the full hint');
    }

    public function testNoFeeStatistics(): void
    {
        $this->als(Rolle::Lesend);

        self::assertSame(403, $this->get('/app/statistik/beitraege')->status);
        self::assertStringNotContainsString('/app/statistik/beitraege', $this->get('/app/statistik')->body);
    }

    public function testThePaidStatusIsVisibleButThePagesBehindItAreNot(): void
    {
        $this->als(Rolle::Lesend);

        $seite = $this->mitgliedSeite('beitraege');

        self::assertStringContainsString('mitglied-beitraege', $seite, 'the tab is there');
        self::assertStringContainsString('bezahlt', $seite);
        self::assertStringContainsString('85,00', $seite);
        self::assertStringNotContainsString('href="/app/beitraege', $seite, 'no link to the fee pages');
        self::assertSame(403, $this->get('/app/beitraege')->status);
        self::assertSame(403, $this->post('/app/beitraege/einzug', ['jahr' => date('Y')])->status);
    }

    public function testTheListsShowFeeDataAndNoButtons(): void
    {
        $this->als(Rolle::Lesend);

        $alle = $this->get('/app/listen/alle-mitglieder')->body;
        self::assertStringContainsString('Status Jahresbeitrag ' . date('Y'), $alle);
        self::assertStringContainsString('Liste exportieren', $alle, 'exports are allowed');

        self::assertSame(200, $this->get('/app/listen/selbst-kassieren')->status);

        $ehrungen = $this->get('/app/listen/ehrungen')->body;
        self::assertStringContainsString('Beispiel, Erika', $ehrungen);
        self::assertStringNotContainsString('>Geehrt</button>', $ehrungen);
        self::assertSame(403, $this->post('/app/listen/ehrungen/vermerk', ['mitglied' => (string) $this->mitgliedId, 'stufe' => '25', 'jahr' => date('Y'), 'aktion' => 'geehrt'])->status);
    }

    public function testNoWayIntoTheAdminArea(): void
    {
        $this->als(Rolle::Lesend);

        $seite = $this->get('/app/mitglieder')->body;
        self::assertStringNotContainsString('href="/admin', $seite);
        self::assertStringNotContainsString('href="/app/beitraege"', $seite);
        self::assertSame(403, $this->get('/admin')->status);
    }

    /** The Vorstand keeps its rights unchanged: the form, no fee tab. */
    public function testTheBoardStillEditsAndSeesThePaidStatus(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->mitgliedSeite();
        self::assertStringContainsString('id="mitglied-formular"', $seite);
        self::assertStringContainsString('reiter=beitraege', $seite);
        self::assertStringContainsString('Status Jahresbeitrag', $this->get('/app/listen/alle-mitglieder')->body);
        self::assertSame(200, $this->get('/app/listen/selbst-kassieren')->status);
        self::assertSame(403, $this->get('/app/beitraege')->status, 'the page "Beiträge" stays the admin\'s');
        self::assertStringNotContainsString('href="/admin', $seite);
    }

    public function testTheReadOnlyViewWorksInTheBarrierefreiModus(): void
    {
        $this->als(Rolle::Lesend, barrierefrei: true);

        $seite = $this->mitgliedSeite();

        self::assertStringContainsString('class="modus-barrierefrei"', $seite);
        self::assertStringContainsString('mitglied-daten-lesen', $seite);
    }
}
