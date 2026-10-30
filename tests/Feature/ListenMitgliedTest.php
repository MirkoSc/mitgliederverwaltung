<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Repository\MitgliedRepository;
use App\Tests\Support\AppTestCase;

/**
 * The page "Listen" opens with the birthdays and shows a person beside the
 * list instead of leading to the members page (docs/spec/09 section 1,
 * 06 section 5, issue #93). Made-up data only.
 */
final class ListenMitgliedTest extends AppTestCase
{
    protected function pdoFabrik(): ?\Closure
    {
        return $this->mitDatenbank();
    }

    /** A member with a birthday this month, so the list of the month shows it. */
    private function mitGeburtstag(int $nummer = 1, string $nachname = 'Musterfrau'): int
    {
        $heute = new \DateTimeImmutable('today');
        $geburtstag = $heute->setDate((int) $heute->format('Y') - 30, (int) $heute->format('n'), 15);
        $mitglied = new MitgliedRepository($this->dienste->pdo())->speichern(
            new Mitglied($nummer, $nachname, vorname: 'Erika', ort: 'Beispielstadt', geburtsdatum: $geburtstag),
            VersionsAnlass::angelegt(),
            null,
        )->mitglied;

        return (int) $mitglied->id;
    }

    public function testTheOverviewIsTheBirthdayList(): void
    {
        $this->als(Rolle::Admin);
        $id = $this->mitGeburtstag();

        $antwort = $this->get('/app/listen');

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('<a href="/app/listen/geburtstage" aria-current="page">Geburtstage</a>', $antwort->body, 'the birthdays are the open tab');
        self::assertStringContainsString('<h2 id="liste-titel">Geburtstage</h2>', $antwort->body);
        self::assertStringContainsString('mitglied=' . $id, $antwort->body, 'the person of the list is a link');
        self::assertStringNotContainsString('Noch ist keine eingerichtet', $antwort->body, 'no overview page any more');
    }

    public function testThePersonLinksOpenTheListPageNotTheMembersPage(): void
    {
        $this->als(Rolle::Admin);
        $id = $this->mitGeburtstag();

        $seite = $this->get('/app/listen/geburtstage', ['zeitraum' => 'jahr'])->body;

        self::assertStringContainsString('href="/app/listen/geburtstage?zeitraum=jahr&amp;mitglied=' . $id . '"', $seite);
        self::assertStringContainsString('hx-target="#listen-bereich"', $seite);
        self::assertStringNotContainsString('href="/app/mitglieder?mitglied=', $seite);
    }

    public function testAnOpenedPersonStandsBesideTheListWithItsTabsAndFilters(): void
    {
        $this->als(Rolle::Admin);
        $id = $this->mitGeburtstag();

        $seite = $this->get('/app/listen/geburtstage', ['zeitraum' => 'jahr', 'mitglied' => (string) $id, 'reiter' => 'daten'])->body;

        self::assertStringContainsString('<div class="geteilt geteilt-mit-detail" id="listen-bereich">', $seite);
        self::assertStringContainsString('<h2 id="mitglied-titel" tabindex="-1" autofocus>Erika Musterfrau</h2>', $seite);
        self::assertStringContainsString('<option value="jahr" selected>', $seite, 'the period stays');
        self::assertStringContainsString('<input type="hidden" name="mitglied" value="' . $id . '">', $seite, 'a new filter keeps the person');
        self::assertStringContainsString('<input type="hidden" name="zurueck" value="/app/listen/geburtstage?zeitraum=jahr">', $seite, 'forms of the person lead back here');
        self::assertStringContainsString('href="/app/listen/geburtstage?zeitraum=jahr&amp;mitglied=' . $id . '&amp;reiter=daten"', $seite, 'tabs of the person are real links on this page');
        self::assertStringContainsString('href="/app/listen/geburtstage?zeitraum=jahr&amp;mitglied=' . $id . '&amp;reiter=verlauf" hx-get', $seite);
        self::assertMatchesRegularExpression('#class="knopf knopf-still detail-schliessen" href="/app/listen/geburtstage\?zeitraum=jahr"#', $seite, 'closing leads to the list without the person');
        self::assertStringNotContainsString('href="/app/mitglieder?', $seite);
        self::assertStringContainsString('aria-current="true"', $seite, 'the open row is marked');
    }

    public function testSortingKeepsTheOpenedPerson(): void
    {
        $this->als(Rolle::Admin);
        $id = $this->mitGeburtstag();

        $seite = $this->get('/app/listen/geburtstage', ['mitglied' => (string) $id])->body;

        self::assertMatchesRegularExpression('#<th scope="col"[^>]*><a href="/app/listen/geburtstage\?sortierung=name&amp;mitglied=' . $id . '&amp;reiter=daten"#', $seite);
    }

    public function testHtmxSwapsTheAreaOnly(): void
    {
        $this->als(Rolle::Admin);
        $id = $this->mitGeburtstag();

        $antwort = $this->get('/app/listen/geburtstage', ['mitglied' => (string) $id], ['hx-request' => 'true', 'hx-target' => 'listen-bereich']);

        self::assertSame(200, $antwort->status);
        self::assertStringStartsWith('<div class="geteilt geteilt-mit-detail" id="listen-bereich">', ltrim($antwort->body));
        self::assertStringNotContainsString('<nav class="reiter" aria-label="Listen">', $antwort->body);
        self::assertStringNotContainsString('<html', $antwort->body);
    }

    public function testSavingInTheOpenedPersonStaysOnTheList(): void
    {
        $this->als(Rolle::Admin);
        $id = $this->mitGeburtstag();
        $zurueck = '/app/listen/geburtstage?zeitraum=jahr';

        $antwort = $this->post("/app/mitglieder/$id/familie/neu", ['name' => 'Familie Muster', 'rolle' => 'zahler', 'zurueck' => $zurueck]);

        self::assertWeiterleitungNach($zurueck . '&mitglied=' . $id . '&reiter=familie', $antwort);
        $danach = $this->get('/app/listen/geburtstage', ['zeitraum' => 'jahr', 'mitglied' => (string) $id, 'reiter' => 'familie']);
        self::assertSame(200, $danach->status);
        self::assertStringContainsString('geteilt-mit-detail', $danach->body);
    }

    public function testADeletedPersonIsAHintNotAnError(): void
    {
        $this->als(Rolle::Admin);

        $antwort = $this->get('/app/listen/geburtstage', ['mitglied' => '987654']);

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Dieses Mitglied gibt es nicht mehr.', $antwort->body);
    }

    public function testTheBoardOpensPersonsToo(): void
    {
        $this->als(Rolle::Vorstand);
        $id = $this->mitGeburtstag();

        $seite = $this->get('/app/listen', ['mitglied' => (string) $id])->body;

        self::assertStringContainsString('<h2 id="mitglied-titel"', $seite);
    }

    public function testTheBarrierefreiModeKeepsTabsAsLinks(): void
    {
        $this->als(Rolle::Admin, barrierefrei: true);
        $id = $this->mitGeburtstag();

        $seite = $this->get('/app/listen/geburtstage', ['mitglied' => (string) $id])->body;

        self::assertStringContainsString('<html lang="de" class="modus-barrierefrei">', $seite);
        self::assertStringContainsString('<nav class="reiter" aria-label="Listen">', $seite);
        self::assertStringContainsString('<a href="/app/listen/geburtstage" aria-current="page">Geburtstage</a>', $seite);
        self::assertStringContainsString('<a href="/app/listen/ehrungen">Ehrungen</a>', $seite);
        self::assertLessThan(strpos($seite, 'id="mitglied-titel"'), strpos($seite, 'id="liste-titel"'), 'the person follows the list in the document order');
    }
}
