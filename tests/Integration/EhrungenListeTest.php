<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Konto;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Listen\EhrungenListe;
use App\Repository\EhrungRepository;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/** Made-up members only. */
final class EhrungenListeTest extends DatabaseTestCase
{
    private EhrungenListe $liste;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        (new SettingRepository($this->pdo()))->set('ehrungsstufen', [25, 40]);
        $this->liste = new EhrungenListe(
            fn(): \PDO => $this->pdo(),
            fn(): SettingRepository => new SettingRepository($this->pdo()),
            fn(): EhrungRepository => new EhrungRepository($this->pdo()),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2027-03-10'),
        );
    }

    private function mitglied(int $nr, string $nachname, ?string $eintritt, ?string $austritt = null): int
    {
        $ergebnis = new MitgliedRepository($this->pdo())->speichern(new Mitglied(
            $nr,
            $nachname,
            vorname: 'Test',
            eintrittsdatum: $eintritt === null ? null : new \DateTimeImmutable($eintritt),
            austrittsdatum: $austritt === null ? null : new \DateTimeImmutable($austritt),
        ), VersionsAnlass::angelegt(), 1);

        return (int) $ergebnis->mitglied->id;
    }

    /** @return list<string> "Nachname|Jahre|geehrt am" */
    private function zeilen(int $jahr, bool $nurOffene = false): array
    {
        $ergebnis = $this->ergebnis($jahr, $nurOffene);

        return array_map(
            static fn($z): string => explode(',', (string) $z->werte['name'])[0] . '|' . $z->werte['jahre'] . '|' . ($z->werte['geehrt_am']?->format('Y-m-d') ?? ''),
            $ergebnis->zeilen,
        );
    }

    private function ergebnis(int $jahr, bool $nurOffene = false): \App\Listen\ListenErgebnis
    {
        return $this->liste->ergebnis(['jahr' => $jahr, 'nur_offene' => $nurOffene], new Konto(1, 'A', Rolle::Vorstand));
    }

    public function testListsMembersWhoReachALevelInTheYear(): void
    {
        $this->mitglied(1, 'Alt', '2002-06-01');   // 25 in 2027
        $this->mitglied(2, 'Aelter', '1987-01-15'); // 40 in 2027
        $this->mitglied(3, 'Jung', '2010-05-05');   // 17: no level
        $this->mitglied(4, 'Knapp', '2002-12-31');  // 25 in 2027 (year counts, not the day)

        self::assertSame(['Aelter|40|', 'Alt|25|', 'Knapp|25|'], $this->zeilen(2027));
        self::assertSame([], $this->zeilen(2028));
    }

    public function testUnknownJoiningDateIsMissingAndCountedInANote(): void
    {
        $this->mitglied(1, 'Alt', '2002-06-01');
        $this->mitglied(2, 'Unbekannt', null);

        $ergebnis = $this->ergebnis(2027);
        self::assertSame(['Alt|25|'], $this->zeilen(2027));
        self::assertCount(1, $ergebnis->hinweise);
        self::assertStringContainsString('1 Mitglied ohne Eintrittsdatum', $ergebnis->hinweise[0]);
        self::assertStringNotContainsString('Unbekannt', $ergebnis->hinweise[0]);
    }

    public function testMemberWhoLeftBeforeTheYearIsLeftOut(): void
    {
        $this->mitglied(1, 'Weg', '2002-06-01', '2026-12-31');
        $this->mitglied(2, 'Geht', '2002-06-01', '2027-08-01');

        self::assertSame(['Geht|25|'], $this->zeilen(2027));
    }

    public function testNoteShowsDateAndOnlyOpenHidesHonoured(): void
    {
        $a = $this->mitglied(1, 'Alt', '2002-06-01');
        $this->mitglied(2, 'Neu', '2002-07-01');
        $ehrungen = new EhrungRepository($this->pdo());
        self::assertTrue($ehrungen->vermerken($a, 25, new \DateTimeImmutable('2027-05-04'), 1));

        self::assertSame(['Alt|25|2027-05-04', 'Neu|25|'], $this->zeilen(2027));
        self::assertSame(['Neu|25|'], $this->zeilen(2027, true));

        self::assertTrue($ehrungen->zuruecknehmen($a, 25));
        self::assertSame(['Alt|25|', 'Neu|25|'], $this->zeilen(2027, true));
    }

    public function testNoteIsPerLevelAndOnlyOnce(): void
    {
        $a = $this->mitglied(1, 'Alt', '1987-06-01');
        $ehrungen = new EhrungRepository($this->pdo());
        self::assertTrue($ehrungen->vermerken($a, 25, new \DateTimeImmutable('2012-05-04'), 1));
        self::assertFalse($ehrungen->vermerken($a, 25, new \DateTimeImmutable('2013-01-01'), 1));

        // The 25-year note does not count for the 40-year level.
        self::assertSame(['Alt|40|'], $this->zeilen(2027));
        self::assertSame(['Alt|25|2012-05-04'], $this->zeilen(2012));
    }

    public function testRowOffersTheMatchingButton(): void
    {
        $a = $this->mitglied(1, 'Alt', '2002-06-01');
        self::assertSame('geehrt', $this->ergebnis(2027)->zeilen[0]->aktion?->felder['aktion']);

        (new EhrungRepository($this->pdo()))->vermerken($a, 25, new \DateTimeImmutable('2027-05-04'), 1);
        self::assertSame('zurueck', $this->ergebnis(2027)->zeilen[0]->aktion?->felder['aktion']);
    }

    public function testNoLevelsMeansEmptyList(): void
    {
        (new SettingRepository($this->pdo()))->set('ehrungsstufen', []);
        $this->mitglied(1, 'Alt', '2002-06-01');

        self::assertSame([], $this->zeilen(2027));
    }
}
