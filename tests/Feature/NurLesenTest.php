<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\Vermerk;
use App\Domain\VersionsAnlass;
use App\Domain\Zahlweise;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Tests\Support\AppTestCase;

/**
 * The role "Nur lesen" end to end (issue #87, docs/spec/02-sicherheit.md
 * section 6): it sees members, lists and whether a member has paid, and
 * nowhere a form or button that changes something. Made-up members only.
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
                iban: 'DE02120300000000202051',
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
        self::assertStringContainsString('DE02120300000000202051', $seite);
        self::assertStringContainsString('Sie können dieses Mitglied ansehen, aber nicht ändern.', $seite);
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
    public function testTheBoardStillEditsAndSeesNoFees(): void
    {
        $this->als(Rolle::Vorstand);

        $seite = $this->mitgliedSeite();
        self::assertStringContainsString('id="mitglied-formular"', $seite);
        self::assertStringNotContainsString('reiter=beitraege', $seite);
        self::assertStringNotContainsString('Status Jahresbeitrag', $this->get('/app/listen/alle-mitglieder')->body);
        self::assertSame(404, $this->get('/app/listen/selbst-kassieren')->status, 'a list without the right does not exist for the account');
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
