<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Herkunft;
use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Domain\MitgliedVersion;
use App\Domain\Nachweis;
use App\Domain\NachweisArt;
use App\Domain\VersionsAnlass;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\EhrungRepository;
use App\Repository\FamilieRepository;
use App\Repository\FunktionRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\MitgliedsnummerVergebenException;
use App\Repository\NachweisRepository;
use App\Repository\VersionKonfliktException;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/**
 * Members, versions, families and search against the real database
 * (docs/spec/07-mitglieder.md sections 1, 3, 7 and 8). Made-up data only.
 */
final class MitgliedRepositoryTest extends DatabaseTestCase
{
    private MitgliedRepository $repo;
    private FamilieRepository $familien;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->repo = new MitgliedRepository($this->pdo());
        $this->familien = new FamilieRepository($this->pdo());
    }

    private function anlegen(Mitglied $mitglied): Mitglied
    {
        return $this->repo->speichern($mitglied, VersionsAnlass::angelegt(), 1)->mitglied;
    }

    private function abteilung(int $index): int
    {
        return (int) new AbteilungRepository($this->pdo())->alle()[$index]->id;
    }

    private function funktion(int $index): int
    {
        return (int) new FunktionRepository($this->pdo())->alle()[$index]->id;
    }

    private function beitragsart(int $index): int
    {
        return (int) new BeitragsartRepository($this->pdo())->alle()[$index]->id;
    }

    private function anzahlVersionen(int $mitgliedId): int
    {
        return count($this->repo->versionen($mitgliedId));
    }

    public function testCreatingWritesVersionOne(): void
    {
        $neu = $this->repo->speichern(
            new Mitglied(
                1001,
                'Mustermann',
                vorname: 'Erika',
                geschlecht: Geschlecht::W,
                geburtsdatum: new \DateTimeImmutable('1980-02-29'),
                abteilungIds: [$this->abteilung(1), $this->abteilung(0)],
                beitragsartId: $this->beitragsart(0),
                funktionIds: [$this->funktion(0)],
                lizenznummer: 'UL-0815',
            ),
            VersionsAnlass::angelegt(),
            7,
            new \DateTimeImmutable('2026-10-01 12:00:00'),
        );

        self::assertTrue($neu->neueVersion);
        self::assertNotNull($neu->mitglied->id);
        self::assertSame(1, $neu->mitglied->version);

        $gelesen = $this->repo->finde($neu->mitglied->id);
        self::assertNotNull($gelesen);
        self::assertSame($neu->mitglied->daten(), $gelesen->daten());
        self::assertSame([$this->abteilung(0), $this->abteilung(1)], $gelesen->abteilungIds);
        self::assertSame('1980-02-29', $gelesen->geburtsdatum?->format('Y-m-d'));
        self::assertSame('2026-10-01 12:00:00', $gelesen->createdAt?->format('Y-m-d H:i:s'));

        $versionen = $this->repo->versionen($neu->mitglied->id);
        self::assertCount(1, $versionen);
        self::assertSame(1, $versionen[0]->nr);
        self::assertSame('angelegt', $versionen[0]->anlass->text);
        self::assertSame(7, $versionen[0]->geaendertVon);
        self::assertSame('2026-10-01 12:00:00', $versionen[0]->geaendertAm->format('Y-m-d H:i:s'));
        self::assertSame('Mustermann', $versionen[0]->daten['nachname']);
        self::assertSame(
            ['abteilungen' => [(string) $this->abteilung(0) => 'Badminton', (string) $this->abteilung(1) => 'Fußball']],
            array_intersect_key($versionen[0]->bezeichnungen(), ['abteilungen' => true]),
        );
        self::assertSame('Aktiv', $versionen[0]->bezeichnungen()['beitragsart']);
        self::assertSame([(string) $this->funktion(0) => 'Übungsleiter'], $versionen[0]->bezeichnungen()['funktionen']);
    }

    public function testSavingWithoutAChangeWritesNoVersion(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster', geburtsdatum: new \DateTimeImmutable('1980-01-01'), abteilungIds: [$this->abteilung(1), $this->abteilung(0)]));
        $updatedAt = $mitglied->updatedAt;

        $ergebnis = $this->repo->speichern(
            // Same data, other id order and a time on the date: no change.
            $mitglied->mit(['abteilungIds' => [$this->abteilung(0), $this->abteilung(1)], 'geburtsdatum' => new \DateTimeImmutable('1980-01-01 15:30')]),
            VersionsAnlass::geaendert(),
            1,
            new \DateTimeImmutable('+1 day'),
        );

        self::assertFalse($ergebnis->neueVersion);
        self::assertSame(1, $ergebnis->mitglied->version);
        self::assertSame(1, $this->anzahlVersionen((int) $mitglied->id));
        self::assertEquals($updatedAt, $this->repo->finde((int) $mitglied->id)?->updatedAt);
    }

    public function testSavingAChangeWritesTheNextVersionAndKeepsTheOldOne(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster', ort: 'Altdorf'));

        $ergebnis = $this->repo->speichern($mitglied->mit(['ort' => 'Neustadt', 'funktionIds' => [$this->funktion(1)]]), VersionsAnlass::geaendert(), 2);

        self::assertTrue($ergebnis->neueVersion);
        self::assertSame(2, $ergebnis->mitglied->version);
        $versionen = $this->repo->versionen((int) $mitglied->id);
        self::assertSame([2, 1], array_map(static fn(MitgliedVersion $v): int => $v->nr, $versionen));
        self::assertSame('Neustadt', $versionen[0]->daten['ort']);
        self::assertSame('geaendert', $versionen[0]->anlass->text);
        self::assertSame('Altdorf', $versionen[1]->daten['ort']);
        self::assertSame([$this->funktion(1)], $this->repo->finde((int) $mitglied->id)?->funktionIds);
    }

    public function testASaveBasedOnAnOlderVersionIsRefused(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster'));
        $this->repo->speichern($mitglied->mit(['ort' => 'Erster']), VersionsAnlass::geaendert(), 1);

        try {
            $this->repo->speichern($mitglied->mit(['ort' => 'Zweiter']), VersionsAnlass::geaendert(), 2);
            self::fail('A conflict was expected.');
        } catch (VersionKonfliktException) {
        }

        self::assertSame('Erster', $this->repo->finde((int) $mitglied->id)?->ort);
        self::assertSame(2, $this->anzahlVersionen((int) $mitglied->id));
    }

    public function testSavingADeletedMemberIsAConflict(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster'));
        $this->repo->loeschen((int) $mitglied->id);

        $this->expectException(VersionKonfliktException::class);
        $this->repo->speichern($mitglied->mit(['ort' => 'X']), VersionsAnlass::geaendert(), 1);
    }

    public function testIbanIsNormalisedAndKeptEvenWhenInvalid(): void
    {
        $gueltig = $this->anlegen(new Mitglied(1, 'A', iban: 'de02 1203 0000 0000 2020 51', bic: 'byla dem1 001'));
        $falsch = $this->anlegen(new Mitglied(2, 'B', iban: 'DE02 1203 0000 0000 2020 52'));

        self::assertSame('DE02120300000000202051', $this->repo->finde((int) $gueltig->id)?->iban);
        self::assertSame('BYLADEM1001', $this->repo->finde((int) $gueltig->id)?->bic);
        self::assertTrue($gueltig->hatGueltigeIban());
        self::assertSame('DE02120300000000202052', $this->repo->finde((int) $falsch->id)?->iban);
        self::assertFalse($falsch->hatGueltigeIban());

        // Typing the same IBAN with spaces again is no change.
        self::assertFalse($this->repo->speichern($gueltig->mit(['iban' => 'DE02 1203 0000 0000 2020 51']), VersionsAnlass::geaendert(), 1)->neueVersion);
    }

    public function testMemberNumberIsUniqueAndTheNextOneIsTheHighestPlusOne(): void
    {
        self::assertSame(1, $this->repo->naechsteNummer());
        $this->anlegen(new Mitglied(17, 'A'));
        $b = $this->anlegen(new Mitglied(5, 'B'));
        self::assertSame(18, $this->repo->naechsteNummer());
        self::assertSame('B', $this->repo->findeNachNummer(5)?->nachname);

        try {
            $this->anlegen(new Mitglied(17, 'Doppelt'));
            self::fail('Number 17 is taken.');
        } catch (MitgliedsnummerVergebenException $e) {
            self::assertSame(17, $e->mitgliedsnummer);
            self::assertStringNotContainsString('Doppelt', $e->getMessage());
        }

        try {
            $this->repo->speichern($b->mit(['mitgliedsnummer' => 17]), VersionsAnlass::geaendert(), 1);
            self::fail('Number 17 is taken.');
        } catch (MitgliedsnummerVergebenException) {
        }

        // Changeable to a free number.
        $this->repo->speichern($b->mit(['mitgliedsnummer' => 99]), VersionsAnlass::geaendert(), 1);
        self::assertSame('B', $this->repo->findeNachNummer(99)?->nachname);
        self::assertSame(100, $this->repo->naechsteNummer());
    }

    public function testSearchFindsOverAllFieldsRegardlessOfCase(): void
    {
        $this->anlegen(new Mitglied(4711, 'Mustermann', vorname: 'Erika', ort: 'Beispielstadt', email: 'erika@example.org'));
        $this->anlegen(new Mitglied(815, 'Schmidt', vorname: 'Max', ort: 'Musterdorf', email: 'max@example.net'));
        $this->anlegen(new Mitglied(42, 'Müller', vorname: 'Jürgen', ort: 'Altdorf', email: 'j@example.com'));

        $namen = fn(string $text): array => array_map(static fn(Mitglied $m): string => $m->nachname, $this->repo->suche($text));

        self::assertSame(['Mustermann'], $namen('MUSTERMANN'), 'Nachname, upper case');
        self::assertSame(['Schmidt'], $namen('max'), 'Vorname, lower case');
        self::assertSame(['Mustermann'], $namen('4711'), 'Mitgliedsnummer');
        self::assertSame(['Mustermann'], $namen('beispielSTADT'), 'Ort');
        self::assertSame(['Müller'], $namen('example.com'), 'E-Mail');
        self::assertSame(['Mustermann', 'Schmidt'], $namen('muster'), 'Nachname and Ort, ordered by name');
        self::assertSame(['Schmidt'], $namen('max musterdorf'), 'every word must match');
        self::assertSame(['Müller'], $namen('MÜLLER'), 'umlauts, case-insensitive');
        self::assertSame([], $namen('%'), 'LIKE wildcards are literal');
        self::assertSame([], $namen('_'), 'LIKE wildcards are literal');
        self::assertSame(['Müller', 'Mustermann', 'Schmidt'], $namen(''), 'empty search lists everybody');
    }

    public function testFormerMembersAreHiddenUnlessAskedFor(): void
    {
        $heute = new \DateTimeImmutable('2026-10-28');
        $this->anlegen(new Mitglied(1, 'Aktuell'));
        $this->anlegen(new Mitglied(2, 'Ausgetreten', austrittsdatum: new \DateTimeImmutable('2026-10-28')));
        $this->anlegen(new Mitglied(3, 'Kuendigt', austrittsdatum: new \DateTimeImmutable('2026-12-31')));

        $namen = static fn(array $liste): array => array_map(static fn(Mitglied $m): string => $m->nachname, $liste);

        self::assertSame(['Aktuell', 'Kuendigt'], $namen($this->repo->suche('', stichtag: $heute)));
        self::assertSame(['Aktuell', 'Ausgetreten', 'Kuendigt'], $namen($this->repo->suche('', true, $heute)));
        self::assertSame(['Aktuell', 'Kuendigt'], $namen($this->repo->alle(stichtag: $heute)));
        self::assertSame(['Aktuell', 'Ausgetreten', 'Kuendigt'], $namen($this->repo->alle(true, $heute)));
    }

    public function testRestoreAppendsANewVersionAndLeavesTheOldOnesUntouched(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster', ort: 'Altdorf', abteilungIds: [$this->abteilung(0)]));
        $zwei = $this->repo->speichern($mitglied->mit(['ort' => 'Neustadt', 'abteilungIds' => [$this->abteilung(1)]]), VersionsAnlass::geaendert(), 1)->mitglied;
        $this->repo->speichern($zwei->mit(['ort' => 'Mitteldorf']), VersionsAnlass::geaendert(), 1);
        $vorher = array_map(static fn(MitgliedVersion $v): array => [$v->nr, $v->anlass->text, $v->daten], $this->repo->versionen((int) $mitglied->id));

        $ergebnis = $this->repo->wiederherstellen((int) $mitglied->id, 1, 3);

        self::assertTrue($ergebnis->neueVersion);
        self::assertSame([], $ergebnis->hinweise);
        self::assertSame(4, $ergebnis->mitglied->version);
        $jetzt = $this->repo->finde((int) $mitglied->id);
        self::assertSame('Altdorf', $jetzt?->ort);
        self::assertSame([$this->abteilung(0)], $jetzt->abteilungIds);

        $versionen = $this->repo->versionen((int) $mitglied->id);
        self::assertSame('wiederhergestellt aus Version 1', $versionen[0]->anlass->text);
        self::assertSame(1, $versionen[0]->anlass->wiederhergestelltAus());
        self::assertSame(3, $versionen[0]->geaendertVon);
        self::assertSame($vorher, array_map(
            static fn(MitgliedVersion $v): array => [$v->nr, $v->anlass->text, $v->daten],
            array_slice($versionen, 1),
        ));
    }

    public function testRestoreWhenTheFamilyIsGone(): void
    {
        $familie = $this->familien->anlegen('Familie Muster');
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster', familieId: $familie->id, familieRolle: FamilienRolle::Kind));
        // Leaving the family empties it, so it is deleted.
        $this->repo->speichern($mitglied->mit(['familieId' => null, 'familieRolle' => null, 'ort' => 'Neustadt']), VersionsAnlass::geaendert(), 1);
        self::assertNull($this->familien->finde($familie->id));

        $ergebnis = $this->repo->wiederherstellen((int) $mitglied->id, 1, 1);

        $jetzt = $this->repo->finde((int) $mitglied->id);
        self::assertNull($jetzt?->familieId);
        self::assertNull($jetzt->familieRolle);
        self::assertSame('', $jetzt->ort, 'the rest is restored');
        self::assertCount(1, $ergebnis->hinweise);
        self::assertStringContainsString('Familie', $ergebnis->hinweise[0]);
        self::assertSame('Familie Muster', $this->repo->version((int) $mitglied->id, 1)?->bezeichnungen()['familie']);
    }

    public function testRestoreLeavesOutDeletedDepartmentsFunctionsAndFeeTypes(): void
    {
        $abteilungen = new AbteilungRepository($this->pdo());
        $funktionen = new FunktionRepository($this->pdo());
        $arten = new BeitragsartRepository($this->pdo());
        $mitglied = $this->anlegen(new Mitglied(
            1,
            'Muster',
            abteilungIds: [$this->abteilung(0), $this->abteilung(2)],
            beitragsartId: $this->beitragsart(6),
            funktionIds: [$this->funktion(2)],
        ));
        [$kegeln, $bahndienst, $mindest] = [$this->abteilung(2), $this->funktion(2), $this->beitragsart(6)];
        $this->repo->speichern($mitglied->mit(['abteilungIds' => [], 'beitragsartId' => null, 'funktionIds' => []]), VersionsAnlass::geaendert(), 1);
        self::assertTrue($abteilungen->loeschen($kegeln));
        self::assertTrue($funktionen->loeschen($bahndienst));
        self::assertTrue($arten->loeschen($mindest));

        $ergebnis = $this->repo->wiederherstellen((int) $mitglied->id, 1, 1);

        $jetzt = $this->repo->finde((int) $mitglied->id);
        self::assertSame([$this->abteilung(0)], $jetzt?->abteilungIds);
        self::assertSame([], $jetzt->funktionIds);
        self::assertNull($jetzt->beitragsartId);
        self::assertCount(3, $ergebnis->hinweise);
    }

    public function testRestoreKeepsTheCurrentNumberWhenTheOldOneIsTakenNow(): void
    {
        $a = $this->anlegen(new Mitglied(10, 'A'));
        $this->repo->speichern($a->mit(['mitgliedsnummer' => 11]), VersionsAnlass::geaendert(), 1);
        $this->anlegen(new Mitglied(10, 'B'));

        $ergebnis = $this->repo->wiederherstellen((int) $a->id, 1, 1);

        self::assertSame(11, $ergebnis->mitglied->mitgliedsnummer);
        self::assertCount(1, $ergebnis->hinweise);
    }

    public function testRestoringTheCurrentStateWritesNoVersion(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster'));

        self::assertFalse($this->repo->wiederherstellen((int) $mitglied->id, 1, 1)->neueVersion);
        self::assertSame(1, $this->anzahlVersionen((int) $mitglied->id));
    }

    public function testRestoringAnUnknownVersionFails(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster'));

        $this->expectException(\OutOfBoundsException::class);
        $this->repo->wiederherstellen((int) $mitglied->id, 5, 1);
    }

    public function testFamilyMembersAndAutomaticDeletionOfAnEmptyFamily(): void
    {
        $familie = $this->familien->anlegen('Familie Muster');
        $kind = $this->anlegen(new Mitglied(2, 'Muster', vorname: 'Kind', geburtsdatum: new \DateTimeImmutable('2015-01-01'), familieId: $familie->id, familieRolle: FamilienRolle::Kind));
        $zahler = $this->anlegen(new Mitglied(1, 'Muster', vorname: 'Zahler', familieId: $familie->id, familieRolle: FamilienRolle::Zahler));

        self::assertSame(['Zahler', 'Kind'], array_map(static fn(Mitglied $m): string => $m->vorname, $this->repo->derFamilie($familie->id)));

        $this->repo->speichern($kind->mit(['familieId' => null, 'familieRolle' => null]), VersionsAnlass::geaendert(), 1);
        self::assertNotNull($this->familien->finde($familie->id), 'the payer is still in it');

        $this->repo->loeschen((int) $zahler->id);
        self::assertNull($this->familien->finde($familie->id), 'nobody left');
    }

    public function testFamilySearchAndImportKey(): void
    {
        $this->familien->anlegen('Familie Muster', 'F-17');
        $this->familien->anlegen('Familie Schmidt');

        self::assertSame(['Familie Muster'], array_map(static fn(\App\Domain\Familie $f): string => $f->name, $this->familien->suche('muster')));
        self::assertCount(2, $this->familien->suche(''));
        self::assertSame('Familie Muster', $this->familien->findeNachImportSchluessel('F-17')?->name);
        self::assertNull($this->familien->findeNachImportSchluessel('F-99'));

        $muster = $this->familien->findeNachImportSchluessel('F-17');
        $this->anlegen(new Mitglied(1, 'Muster', familieId: $muster?->id, familieRolle: FamilienRolle::Zahler));
        self::assertFalse($this->familien->loeschenWennLeer((int) $muster?->id), 'not empty');
        $leer = $this->familien->suche('schmidt')[0];
        self::assertTrue($this->familien->loeschenWennLeer($leer->id));
        self::assertNull($this->familien->finde($leer->id));

        $this->familien->umbenennen((int) $muster?->id, 'Familie Muster-Schmidt');
        self::assertSame('Familie Muster-Schmidt', $this->familien->finde((int) $muster?->id)?->name);
    }

    /**
     * Deleting a member (07 section 1) takes its versions, proofs, annual
     * fees and honours along and hands back the proof files to delete.
     */
    public function testDeletingAMemberTakesEverythingAlong(): void
    {
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster', abteilungIds: [$this->abteilung(0)], funktionIds: [$this->funktion(0)]));
        $andere = $this->anlegen(new Mitglied(2, 'Andere'));
        $id = (int) $mitglied->id;
        $nachweise = new NachweisRepository($this->pdo());
        $nachweise->anlegen(new Nachweis($id, NachweisArt::Rente, blobId: 501));
        $nachweise->anlegen(new Nachweis($id, NachweisArt::Sonstiges));
        $nachweise->anlegen(new Nachweis((int) $andere->id, NachweisArt::Studium, blobId: 777));
        new JahresbeitragRepository($this->pdo())->anlegenWennFehlt(new Jahresbeitrag($id, 2026, 8500));
        new EhrungRepository($this->pdo())->vermerken($id, 25, new \DateTimeImmutable('2026-01-01'), 1);

        self::assertSame([501], $this->repo->loeschen($id));

        self::assertNull($this->repo->finde($id));
        foreach (['mitglied_version', 'mitglied_abteilung', 'mitglied_funktion', 'nachweis', 'jahresbeitrag', 'ehrung'] as $tabelle) {
            $stmt = $this->pdo()->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE mitglied_id = ?', $tabelle));
            $stmt->execute([$id]);
            self::assertSame(0, (int) $stmt->fetchColumn(), $tabelle);
        }
        self::assertCount(1, $nachweise->fuerMitglied((int) $andere->id));
        self::assertSame(1, $this->anzahlVersionen((int) $andere->id));
    }

    public function testOriginIsStoredButNotVersioned(): void
    {
        $mitglied = $this->repo->speichern(new Mitglied(1, 'Import', herkunft: Herkunft::Import), VersionsAnlass::importiert(), null)->mitglied;
        self::assertSame(Herkunft::Import, $this->repo->finde((int) $mitglied->id)?->herkunft);
        self::assertSame('importiert', $this->repo->versionen((int) $mitglied->id)[0]->anlass->text);
        self::assertNull($this->repo->versionen((int) $mitglied->id)[0]->geaendertVon);

        $ergebnis = $this->repo->speichern($mitglied->mit(['herkunft' => Herkunft::Manuell]), VersionsAnlass::geaendert(), 1);

        self::assertFalse($ergebnis->neueVersion);
        self::assertSame(Herkunft::Manuell, $this->repo->finde((int) $mitglied->id)?->herkunft);
        self::assertSame(1, $this->anzahlVersionen((int) $mitglied->id));
    }

    /** A caller's transaction (an import step) wraps the save instead of nesting. */
    public function testASaveJoinsAnOpenTransaction(): void
    {
        $this->pdo()->beginTransaction();
        $mitglied = $this->anlegen(new Mitglied(1, 'Muster'));
        $this->pdo()->rollBack();

        self::assertNull($this->repo->finde((int) $mitglied->id));
        self::assertSame([], $this->repo->versionen((int) $mitglied->id));
    }
}
