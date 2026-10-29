<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Mitglied;
use App\Domain\MitgliederFilter;
use App\Domain\VersionsAnlass;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\MitgliederListeRepository;
use App\Repository\MitgliedRepository;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * Search, filters, sort order and paging of the member list against the
 * real database (docs/spec/06-oberflaeche.md sections 2 and 3, 07 section
 * 8). Made-up data only.
 */
final class MitgliederListeRepositoryTest extends DatabaseTestCase
{
    private MitgliedRepository $mitglieder;
    private MitgliederListeRepository $liste;
    private \DateTimeImmutable $heute;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->mitglieder = new MitgliedRepository($this->pdo());
        $this->liste = new MitgliederListeRepository($this->pdo());
        $this->heute = new \DateTimeImmutable('2026-06-15');
    }

    private function anlegen(Mitglied $mitglied): Mitglied
    {
        return $this->mitglieder->speichern($mitglied, VersionsAnlass::angelegt(), 1)->mitglied;
    }

    private function abteilung(string $name): int
    {
        foreach (new AbteilungRepository($this->pdo())->alle() as $abteilung) {
            if ($abteilung->name === $name) {
                return (int) $abteilung->id;
            }
        }
        self::fail('No department ' . $name);
    }

    private function beitragsart(string $name): int
    {
        foreach (new BeitragsartRepository($this->pdo())->alle() as $art) {
            if ($art->name === $name) {
                return (int) $art->id;
            }
        }
        self::fail('No fee type ' . $name);
    }

    /**
     * @param array<string, mixed> $daten
     * @return list<string> "Nachname Vorname" of the page, in order
     */
    private function namen(array $daten, ?MitgliederFilter $filter = null): array
    {
        $seite = $this->liste->seite($filter ?? MitgliederFilter::aus($daten), $this->heute);

        return array_map(static fn($z): string => $z->nachname . ' ' . $z->vorname, $seite->zeilen);
    }

    private function beispiele(): void
    {
        $this->anlegen(new Mitglied(
            1001, 'Mustermann', vorname: 'Erika', ort: 'Beispielstadt', plz: '12345', email: 'erika@example.org',
            abteilungIds: [$this->abteilung('Fußball'), $this->abteilung('Kegeln')],
            beitragsartId: $this->beitragsart('Aktiv'),
        ));
        $this->anlegen(new Mitglied(
            1002, 'Beispiel', vorname: 'Karl', ort: 'Musterhausen', plz: '54321', email: 'karl@example.net', aktiv: false,
            abteilungIds: [$this->abteilung('Kegeln')],
            beitragsartId: $this->beitragsart('Passiv'),
        ));
        $this->anlegen(new Mitglied(
            1003, 'Probe', vorname: 'Anna', ort: 'Beispielstadt',
            abteilungIds: [$this->abteilung('Badminton')],
        ));
        $this->anlegen(new Mitglied(
            1004, 'Altmann', vorname: 'Hans', ort: 'Beispielstadt', aktiv: false,
            austrittsdatum: new \DateTimeImmutable('2026-01-31'), austrittsgrund: 'Umzug',
            beitragsartId: $this->beitragsart('Passiv'),
        ));
    }

    public function testFormerMembersAreHiddenByDefault(): void
    {
        $this->beispiele();

        self::assertSame(['Mustermann Erika', 'Beispiel Karl', 'Probe Anna'], $this->namen(['sortierung' => 'nummer']));
        self::assertNotContains('Altmann Hans', $this->namen([]));
        self::assertContains('Altmann Hans', $this->namen(['ehemalige' => '1']));
    }

    /** "Ehemalig" is computed from the date: leaving in the future does not count yet, leaving today does. */
    public function testFormerIsComputedFromTheDateOfLeaving(): void
    {
        $this->anlegen(new Mitglied(2001, 'Bald', austrittsdatum: new \DateTimeImmutable('2026-06-16')));
        $this->anlegen(new Mitglied(2002, 'Heute', austrittsdatum: new \DateTimeImmutable('2026-06-15')));

        self::assertSame(['Bald '], $this->namen([]));
        self::assertSame(['Bald ', 'Heute '], $this->namen(['ehemalige' => '1']));

        $zeilen = $this->liste->seite(MitgliederFilter::aus(['ehemalige' => '1']), $this->heute)->zeilen;
        self::assertFalse($zeilen[0]->ehemalig);
        self::assertTrue($zeilen[1]->ehemalig);
        self::assertSame('ehemalig', $zeilen[1]->status());
    }

    public function testTheSearchLooksInEverySearchField(): void
    {
        $this->beispiele();

        self::assertSame(['Mustermann Erika'], $this->namen(['text' => 'mustermann']), 'Nachname');
        self::assertSame(['Probe Anna'], $this->namen(['text' => 'anna']), 'Vorname');
        self::assertSame(['Beispiel Karl'], $this->namen(['text' => '1002']), 'Mitgliedsnummer');
        self::assertSame(['Beispiel Karl'], $this->namen(['text' => 'musterhausen']), 'Ort');
        self::assertSame(['Beispiel Karl'], $this->namen(['text' => 'karl@example']), 'E-Mail');
        self::assertSame([], $this->namen(['text' => 'nirgendwo']));
    }

    public function testEveryWordMustMatchSomewhere(): void
    {
        $this->beispiele();

        self::assertSame(['Mustermann Erika'], $this->namen(['text' => 'erika muster']), 'name and surname in any order');
        self::assertSame(['Mustermann Erika'], $this->namen(['text' => 'Mustermann, Erika']), 'with the comma of the list');
        self::assertSame(['Probe Anna'], $this->namen(['text' => 'anna beispielstadt']), 'name and place');
        self::assertSame([], $this->namen(['text' => 'erika karl']));
    }

    /** The user types a wildcard - it is a character, not a pattern. */
    public function testWildcardsInTheTextAreOrdinaryCharacters(): void
    {
        $this->beispiele();

        self::assertSame([], $this->namen(['text' => '%']));
        self::assertSame([], $this->namen(['text' => '_']));
        self::assertSame([], $this->namen(['text' => 'Mus_ermann']));
    }

    public function testFiltersCombine(): void
    {
        $this->beispiele();
        $kegeln = (string) $this->abteilung('Kegeln');

        self::assertSame(['Beispiel Karl', 'Mustermann Erika'], $this->namen(['abteilung' => $kegeln]));
        self::assertSame(['Beispiel Karl'], $this->namen(['abteilung' => $kegeln, 'status' => 'passiv']));
        self::assertSame(['Mustermann Erika'], $this->namen(['abteilung' => $kegeln, 'status' => 'aktiv']));
        self::assertSame(['Beispiel Karl'], $this->namen(['abteilung' => $kegeln, 'beitragsart' => (string) $this->beitragsart('Passiv')]));
        self::assertSame([], $this->namen(['abteilung' => $kegeln, 'beitragsart' => (string) $this->beitragsart('Familie')]));
        self::assertSame(['Beispiel Karl'], $this->namen(['abteilung' => $kegeln, 'status' => 'passiv', 'text' => 'karl']));
        self::assertSame([], $this->namen(['abteilung' => $kegeln, 'status' => 'passiv', 'text' => 'erika']));
    }

    public function testTheFilterPassivAndFormerWorkTogether(): void
    {
        $this->beispiele();
        $passiv = (string) $this->beitragsart('Passiv');

        self::assertSame(['Beispiel Karl'], $this->namen(['beitragsart' => $passiv]));
        self::assertSame(['Altmann Hans', 'Beispiel Karl'], $this->namen(['beitragsart' => $passiv, 'ehemalige' => '1']));
        self::assertSame(['Altmann Hans', 'Beispiel Karl'], $this->namen(['status' => 'passiv', 'ehemalige' => '1']));
    }

    public function testASearchFindsFormerMembersOnlyOnRequest(): void
    {
        $this->beispiele();

        self::assertSame([], $this->namen(['text' => 'altmann']));
        self::assertSame(['Altmann Hans'], $this->namen(['text' => 'altmann', 'ehemalige' => '1']));
    }

    public function testSortOrder(): void
    {
        $this->beispiele();

        self::assertSame(['Beispiel Karl', 'Mustermann Erika', 'Probe Anna'], $this->namen([]));
        self::assertSame(['Probe Anna', 'Mustermann Erika', 'Beispiel Karl'], $this->namen(['sortierung' => 'name-ab']));
        self::assertSame(['Mustermann Erika', 'Beispiel Karl', 'Probe Anna'], $this->namen(['sortierung' => 'nummer']));
        self::assertSame(['Probe Anna', 'Beispiel Karl', 'Mustermann Erika'], $this->namen(['sortierung' => 'nummer-ab']));
        self::assertSame(['Mustermann Erika', 'Probe Anna', 'Beispiel Karl'], $this->namen(['sortierung' => 'ort']), 'Beispielstadt (Mustermann, Probe) before Musterhausen');
        self::assertSame(['Mustermann Erika', 'Beispiel Karl', 'Probe Anna'], $this->namen(['sortierung' => 'beitragsart']), 'Aktiv, Passiv, then without');
    }

    public function testTheRowsCarryWhatTheListShows(): void
    {
        $this->beispiele();

        $zeile = $this->liste->seite(MitgliederFilter::aus(['text' => 'erika']), $this->heute)->zeilen[0];

        self::assertSame('Mustermann, Erika', $zeile->name());
        self::assertSame(1001, $zeile->mitgliedsnummer);
        self::assertSame('12345 Beispielstadt', $zeile->ortAnzeige());
        self::assertSame('Aktiv', $zeile->beitragsart);
        self::assertSame(['Fußball', 'Kegeln'], $zeile->abteilungen, 'in the order of the settings');
        self::assertSame('aktiv', $zeile->status());

        $passiv = $this->liste->seite(MitgliederFilter::aus(['text' => 'karl']), $this->heute)->zeilen[0];
        self::assertSame('passiv', $passiv->status());

        $ohne = $this->liste->seite(MitgliederFilter::aus(['text' => 'anna']), $this->heute)->zeilen[0];
        self::assertSame('', $ohne->beitragsart);
        self::assertSame('Probe, Anna', $ohne->name());
    }

    public function testPagesOfFifty(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->anlegen(new Mitglied($i, sprintf('Test%03d', $i)));
        }

        $erste = $this->liste->seite(MitgliederFilter::aus([]), $this->heute);
        self::assertSame(120, $erste->gesamt);
        self::assertSame(3, $erste->seiten());
        self::assertCount(50, $erste->zeilen);
        self::assertSame('Test001', $erste->zeilen[0]->nachname);
        self::assertFalse($erste->hatVorherige());
        self::assertTrue($erste->hatNaechste());

        $dritte = $this->liste->seite(MitgliederFilter::aus(['seite' => '3']), $this->heute);
        self::assertCount(20, $dritte->zeilen);
        self::assertSame('Test101', $dritte->zeilen[0]->nachname);
        self::assertTrue($dritte->hatVorherige());
        self::assertFalse($dritte->hatNaechste());

        $gefiltert = $this->liste->seite(MitgliederFilter::aus(['text' => 'Test11']), $this->heute);
        self::assertSame(10, $gefiltert->gesamt, 'Test110 .. Test119');
    }

    /** A filter can make the list shorter than the page the visitor was on. */
    public function testAPageBeyondTheEndShowsTheLastPage(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->anlegen(new Mitglied($i, sprintf('Test%03d', $i)));
        }

        $seite = $this->liste->seite(MitgliederFilter::aus(['seite' => '9']), $this->heute);

        self::assertSame(2, $seite->seite);
        self::assertCount(10, $seite->zeilen);
    }

    public function testAnEmptyListHasOnePage(): void
    {
        $seite = $this->liste->seite(MitgliederFilter::aus(['seite' => '5']), $this->heute);

        self::assertSame([], $seite->zeilen);
        self::assertSame(0, $seite->gesamt);
        self::assertSame(1, $seite->seiten());
        self::assertSame(1, $seite->seite);
        self::assertSame('Kein Mitglied gefunden.', $seite->stand());
    }
}
