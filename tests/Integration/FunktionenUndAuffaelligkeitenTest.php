<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Funktion;
use App\Domain\Mitglied;
use App\Domain\VersionsAnlass;
use App\Listen\AuffaelligkeitenListe;
use App\Listen\FunktionenListe;
use App\Repository\AbteilungRepository;
use App\Repository\FunktionRepository;
use App\Repository\MitgliedRepository;
use App\Service\Migration\Migrator;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\HinweisLader;
use App\Tests\Support\DatabaseTestCase;

/**
 * The lists "Funktionen" and "Auffälligkeiten" against the real database
 * (docs/spec/09-listen-und-exporte.md section 2): former members are left
 * out, the filters by function and by kind of hint work. Made-up data only.
 */
final class FunktionenUndAuffaelligkeitenTest extends DatabaseTestCase
{
    private MitgliedRepository $mitglieder;
    private FunktionRepository $funktionen;
    private \DateTimeImmutable $heute;
    private int $trainer;
    private int $kassierer;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->mitglieder = new MitgliedRepository($this->pdo());
        $this->funktionen = new FunktionRepository($this->pdo());
        $this->heute = new \DateTimeImmutable('2026-06-15');
        $this->trainer = (int) $this->funktionen->speichern(new Funktion('Testtrainer', mitLizenz: true))->id;
        $this->kassierer = (int) $this->funktionen->speichern(new Funktion('Testkassierer'))->id;

        $abteilung = (new AbteilungRepository($this->pdo()))->alle()[0];

        // Active, with function and licence number, but no entry date and no gender: two hints.
        $this->anlegen(new Mitglied(
            mitgliedsnummer: 9001,
            nachname: 'Aktiv',
            vorname: 'Anna',
            telefon: '0301234',
            email: 'anna@example.test',
            aktiv: true,
            abteilungIds: [(int) $abteilung->id],
            funktionIds: [$this->trainer, $this->kassierer],
            lizenznummer: 'LIZ-42',
        ));
        // Former member with a function: must not appear anywhere.
        $this->anlegen(new Mitglied(
            mitgliedsnummer: 9002,
            nachname: 'Ehemalig',
            vorname: 'Egon',
            austrittsdatum: new \DateTimeImmutable('2026-01-01'),
            funktionIds: [$this->trainer],
        ));
        // Active without function, complete enough and paid by cash: has no hint at all.
        $this->anlegen(new Mitglied(
            mitgliedsnummer: 9003,
            nachname: 'Ohne',
            vorname: 'Olaf',
            geschlecht: \App\Domain\Geschlecht::M,
            geburtsdatum: new \DateTimeImmutable('1980-05-05'),
            eintrittsdatum: new \DateTimeImmutable('2010-01-01'),
            beitragsartId: $this->familienArt(),
            zahlart: \App\Domain\Zahlart::Selbst,
        ));
    }

    /** The seeded fee type "Familie": no rule that could hint at this member. */
    private function familienArt(): int
    {
        foreach (new \App\Repository\BeitragsartRepository($this->pdo())->alle() as $art) {
            if ($art->name === 'Familie') {
                return (int) $art->id;
            }
        }
        self::fail('No seeded fee type "Familie".');
    }

    private function anlegen(Mitglied $mitglied): void
    {
        $this->mitglieder->speichern($mitglied, VersionsAnlass::angelegt(), 1);
    }

    public function testFunktionenLeaveFormerMembersOutAndFilterBySingleFunction(): void
    {
        $repo = $this->funktionen;

        $alle = $repo->zuordnungen(null, $this->heute);
        self::assertCount(2, $alle, 'Only the two entries of the active member Aktiv.');
        foreach ($alle as $zeile) {
            self::assertSame('Aktiv', $zeile->nachname);
            self::assertSame('LIZ-42', $zeile->lizenznummer);
        }

        $nurTrainer = $repo->zuordnungen($this->trainer, $this->heute);
        self::assertCount(1, $nurTrainer);
        self::assertSame('Testtrainer', $nurTrainer[0]->funktion);
        self::assertNotSame('', $nurTrainer[0]->abteilungen);

        $zeilen = FunktionenListe::zeilen($nurTrainer);
        self::assertSame('Aktiv, Anna', $zeilen[0]->werte['name']);
        self::assertSame('0301234, anna@example.test', $zeilen[0]->werte['kontakt']);
    }

    public function testAuffaelligkeitenListeRowsPerHintAndFilterByKind(): void
    {
        $lader = HinweisLader::aus($this->pdo());
        $eintraege = $lader->fuerAlle($this->heute);

        $namen = array_map(static fn($e): string => $e->mitglied->nachname, $eintraege);
        self::assertContains('Aktiv', $namen);
        self::assertNotContains('Ehemalig', $namen, 'Former members have no hints in the list.');
        self::assertNotContains('Ohne', $namen, 'A member without hints is not listed.');

        $alle = AuffaelligkeitenListe::zeilen($eintraege, 'alle');
        self::assertNotEmpty($alle);

        $eintritt = AuffaelligkeitenListe::zeilen($eintraege, HinweisArt::EintrittsdatumUnbekannt->value);
        self::assertCount(1, $eintritt);
        self::assertSame('Eintrittsdatum unbekannt', $eintritt[0]->werte['art']);

        $blsv = AuffaelligkeitenListe::zeilen($eintraege, HinweisArt::BlsvAngabenFehlen->value);
        self::assertCount(1, $blsv);
        self::assertSame('Aktiv, Anna', $blsv[0]->werte['name']);

        self::assertSame([], AuffaelligkeitenListe::zeilen($eintraege, HinweisArt::KindIst18->value));
    }
}
