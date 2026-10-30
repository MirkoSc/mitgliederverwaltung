<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\MitgliederFilter;
use App\Domain\VersionsAnlass;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FamilieRepository;
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

    public function testThePageSizeCanBeChosenUpToAll(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->anlegen(new Mitglied($i, sprintf('Test%03d', $i)));
        }

        $hundert = $this->liste->seite(MitgliederFilter::aus(['anzahl' => '100']), $this->heute);
        self::assertCount(100, $hundert->zeilen);
        self::assertSame(2, $hundert->seiten());

        $zweite = $this->liste->seite(MitgliederFilter::aus(['anzahl' => '100', 'seite' => '2']), $this->heute);
        self::assertCount(20, $zweite->zeilen);
        self::assertSame('Test101', $zweite->zeilen[0]->nachname);

        $alle = $this->liste->seite(MitgliederFilter::aus(['anzahl' => 'alle', 'seite' => '3']), $this->heute);
        self::assertCount(120, $alle->zeilen);
        self::assertSame(1, $alle->seiten());
        self::assertSame(1, $alle->seite, 'all on one page');
        self::assertFalse($alle->hatNaechste());
    }

    private function suchBeispiele(): void
    {
        $this->anlegen(new Mitglied(
            3001, 'Müller', vorname: 'Anna', strasse: 'Hauptstraße 12', plz: '12345', ort: 'Musterort',
            geburtsdatum: new \DateTimeImmutable('1980-02-01'),
            iban: 'DE89 3704 0044 0532 0130 00', kontoinhaber: 'Peter Zahler',
            bemerkung: 'Passiv wegen Umzug', telefon: '0123 4567',
        ));
        $this->anlegen(new Mitglied(
            3002, 'Müller', vorname: 'Karl', strasse: 'Bahnhofstraße 3', plz: '54321', ort: 'Beispielstadt',
            geburtsdatum: new \DateTimeImmutable('1975-11-30'), handy: '0171 99',
        ));
        $this->anlegen(new Mitglied(
            3003, 'Probe', vorname: 'Max', strasse: 'Hauptstraße 14', ort: 'Musterort',
            geburtsdatum: new \DateTimeImmutable('2010-02-01'), email: 'max@example.org',
        ));
    }

    /** @return list<string> */
    private function nummern(string $text): array
    {
        $seite = $this->liste->seite(MitgliederFilter::aus(['text' => $text, 'sortierung' => 'nummer']), $this->heute);

        return array_map(static fn($z): string => (string) $z->mitgliedsnummer, $seite->zeilen);
    }

    public function testTheDateOfBirthIsFoundInEveryWritingAsAnExactDate(): void
    {
        $this->suchBeispiele();

        foreach (['1.2.1980', '01.02.1980', '1980-02-01', '1.2.80', '01.02.1980.'] as $schreibweise) {
            self::assertSame(['3001'], $this->nummern($schreibweise), $schreibweise);
        }
        self::assertSame(['3003'], $this->nummern('1.2.10'), 'two digits of the year: not in the future');
        self::assertSame([], $this->nummern('2.2.1980'), 'another day is no hit');
        self::assertSame([], $this->nummern('31.02.1980'), 'no valid date');
        self::assertSame(['3001'], $this->nummern('Anna 01.02.1980'));
        self::assertSame([], $this->nummern('Karl 01.02.1980'));
    }

    public function testAFourDigitNumberAlsoMatchesTheYearOfBirth(): void
    {
        $this->suchBeispiele();

        self::assertSame(['3001'], $this->nummern('Müller 1980'));
        self::assertSame(['3002'], $this->nummern('Müller 1975'));
        self::assertSame(['3001'], $this->nummern('1980 Musterort'), 'the year of birth and a place');
        self::assertSame(['3003'], $this->nummern('2010 Musterort'));
        self::assertSame([], $this->nummern('Müller 1999'));
    }

    public function testTheIbanIsComparedWithoutBlanksAndCaseAndAPieceIsEnough(): void
    {
        $this->suchBeispiele();

        self::assertSame(['3001'], $this->nummern('DE89370400440532013000'), 'whole IBAN without blanks');
        self::assertSame(['3001'], $this->nummern('de89370400440532013000'), 'lower case');
        self::assertSame(['3001'], $this->nummern('DE89 3704 0044 0532 0130 00'), 'with blanks: every group is a piece');
        self::assertSame(['3001'], $this->nummern('013000'), 'last digits');
        self::assertSame(['3001'], $this->nummern('0044'), 'a piece in the middle');
        self::assertSame([], $this->nummern('DE00'), 'not an IBAN of a member');
    }

    public function testAnAddressCombinesWithTheName(): void
    {
        $this->suchBeispiele();

        self::assertSame(['3001', '3003'], $this->nummern('Hauptstr Musterort'));
        self::assertSame(['3001'], $this->nummern('Hauptstr 12 Musterort'));
        self::assertSame(['3001'], $this->nummern('Hauptstr. 12 Musterort'), 'a full stop at the end of a word is ignored');
        self::assertSame(['3001'], $this->nummern('Müller Hauptstr'));
        self::assertSame(['3002'], $this->nummern('Müller Bahnhof 54321'));
        self::assertSame(['3001'], $this->nummern('hauptstraße 12345'), 'postcode, case does not matter');
    }

    public function testRemarkAccountHolderAndPhoneAreSearched(): void
    {
        $this->suchBeispiele();

        self::assertSame(['3001'], $this->nummern('Umzug'), 'Bemerkung');
        self::assertSame(['3001'], $this->nummern('Zahler'), 'Kontoinhaber');
        self::assertSame(['3001'], $this->nummern('4567'), 'Telefon');
        self::assertSame(['3002'], $this->nummern('0171'), 'Handy');
    }

    public function testAWordWithoutAHitFindsNothing(): void
    {
        $this->suchBeispiele();

        self::assertSame([], $this->nummern('Müller Nirgendwo'));
        self::assertSame([], $this->nummern('Nirgendwo'));
        self::assertSame(['3001', '3002', '3003'], $this->nummern('...'), 'nothing but full stops is no word: no restriction');
    }

    /** Payer "Familie Muster" has the fee type; the family members find it through the payer. */
    public function testTheFeeTypeAlsoFindsTheMembersOfFamiliesWhosePayerHasIt(): void
    {
        $familien = new FamilieRepository($this->pdo());
        $familie = $familien->anlegen('Familie Muster');
        $andere = $familien->anlegen('Familie Zwei');
        $art = $this->beitragsart('Aktiv');

        $this->anlegen(new Mitglied(
            4001, 'Muster', vorname: 'Paula', beitragsartId: $this->beitragsart('Familie'),
            familieId: $familie->id, familieRolle: FamilienRolle::Zahler,
        ));
        $this->anlegen(new Mitglied(4002, 'Muster', vorname: 'Tom', familieId: $familie->id, familieRolle: FamilienRolle::Kind));
        $this->anlegen(new Mitglied(4003, 'Muster', vorname: 'Eva', familieId: $familie->id, familieRolle: FamilienRolle::Elternteil, beitragsartId: $art));
        $this->anlegen(new Mitglied(
            4004, 'Zwei', vorname: 'Zora', beitragsartId: $art, familieId: $andere->id, familieRolle: FamilienRolle::Zahler,
        ));
        $this->anlegen(new Mitglied(4005, 'Zwei', vorname: 'Kai', familieId: $andere->id, familieRolle: FamilienRolle::Kind));
        $this->anlegen(new Mitglied(4006, 'Allein', vorname: 'Ali', beitragsartId: $this->beitragsart('Familie')));

        $zeilen = $this->liste->seite(
            MitgliederFilter::aus(['beitragsart' => (string) $this->beitragsart('Familie'), 'sortierung' => 'nummer']),
            $this->heute,
        )->zeilen;

        self::assertSame([4001, 4002, 4003, 4006], array_map(static fn($z): int => $z->mitgliedsnummer, $zeilen));
        // Eva has her own fee type "Aktiv" but belongs to the family of the payer.
        self::assertSame(['', 'über Familie Muster', 'über Familie Muster', ''], array_map(static fn($z): string => $z->familienHinweis(), $zeilen));
        self::assertSame('Familie Muster', $zeilen[2]->ueberFamilie);

        // The other fee type: Zora herself, and Kai through her family - not the Musters.
        $aktiv = $this->liste->seite(MitgliederFilter::aus(['beitragsart' => (string) $art, 'sortierung' => 'nummer']), $this->heute);
        self::assertSame([4003, 4004, 4005], array_map(static fn($z): int => $z->mitgliedsnummer, $aktiv->zeilen));
        self::assertSame(['', '', 'über Familie Zwei'], array_map(static fn($z): string => $z->familienHinweis(), $aktiv->zeilen));
        self::assertSame(3, $aktiv->gesamt, 'the count includes the family members');

        // No filter on the fee type: nobody is marked.
        foreach ($this->liste->seite(MitgliederFilter::aus([]), $this->heute)->zeilen as $zeile) {
            self::assertSame('', $zeile->familienHinweis());
        }
    }
}
