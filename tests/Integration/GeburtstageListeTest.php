<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Konto;
use App\Domain\Mitglied;
use App\Domain\Rolle;
use App\Domain\VersionsAnlass;
use App\Listen\GeburtstageListe;
use App\Listen\ListenAnsicht;
use App\Repository\MitgliedRepository;
use App\Repository\SettingRepository;
use App\Service\Migration\Migrator;
use App\Tests\Support\DatabaseTestCase;

/** Made-up members only. */
final class GeburtstageListeTest extends DatabaseTestCase
{
    private GeburtstageListe $liste;

    protected function setUp(): void
    {
        parent::setUp();
        new Migrator($this->pdo(), $this->migrationsDir())->migrate();
        $this->liste = new GeburtstageListe(
            fn(): \PDO => $this->pdo(),
            fn(): SettingRepository => new SettingRepository($this->pdo()),
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2027-03-10'),
        );
    }

    private function mitglied(int $nr, string $nachname, ?string $geboren, ?string $austritt = null): void
    {
        new MitgliedRepository($this->pdo())->speichern(new Mitglied(
            $nr,
            $nachname,
            vorname: 'Test',
            geburtsdatum: $geboren === null ? null : new \DateTimeImmutable($geboren),
            austrittsdatum: $austritt === null ? null : new \DateTimeImmutable($austritt),
        ), VersionsAnlass::angelegt(), 1);
    }

    /**
     * @param array<string, string|int|bool> $filter
     * @return list<string> "Nachname|YYYY-MM-DD|Alter|rund?"
     */
    private function zeilen(array $filter): array
    {
        $ergebnis = $this->liste->ergebnis($filter + ['zeitraum' => 'monat', 'datum' => '', 'nur_runde' => false], new Konto(1, 'A', Rolle::Vorstand));

        return array_map(
            static fn($z): string => explode(',', (string) $z->werte['name'])[0] . '|' . $z->werte['geburtstag']->format('Y-m-d') . '|' . $z->werte['alter'] . ($z->marke !== null ? '|rund' : ''),
            $ergebnis->zeilen,
        );
    }

    public function testMonthShowsNewAgeSortedByDayAndMarksRoundOnes(): void
    {
        $this->mitglied(1, 'Spaet', '1977-03-20');   // 50, round
        $this->mitglied(2, 'Frueh', '2000-03-02');   // 27
        $this->mitglied(3, 'Anderer', '1990-04-02'); // other month
        $this->mitglied(4, 'Ohne', null);

        self::assertSame(['Frueh|2027-03-02|27', 'Spaet|2027-03-20|50|rund'], $this->zeilen([]));
        self::assertSame(['Spaet|2027-03-20|50|rund'], $this->zeilen(['nur_runde' => true]));
    }

    public function testRoundAgesComeFromTheSetting(): void
    {
        $this->mitglied(1, 'Zwanzig', '2007-03-05');
        self::assertSame(['Zwanzig|2027-03-05|20'], $this->zeilen([]));

        new SettingRepository($this->pdo())->set('runde_geburtstage', [20]);
        self::assertSame(['Zwanzig|2027-03-05|20|rund'], $this->zeilen([]));
    }

    public function testWeekOverNewYear(): void
    {
        $this->mitglied(1, 'Silvester', '1980-12-31');
        $this->mitglied(2, 'Neujahr', '1965-01-01');
        $this->mitglied(3, 'Spaeter', '1965-01-05');

        self::assertSame(
            ['Silvester|2026-12-31|46', 'Neujahr|2027-01-01|62'],
            $this->zeilen(['zeitraum' => 'woche', 'datum' => '2026-12-30']),
        );
        self::assertSame(['Spaeter|2027-01-05|62'], $this->zeilen(['zeitraum' => 'woche', 'datum' => '2027-01-05']));
    }

    public function testYearListsAllInCalendarOrder(): void
    {
        $this->mitglied(1, 'Dez', '1970-12-24');
        $this->mitglied(2, 'Jan', '1970-01-24');

        self::assertSame(['Jan|2027-01-24|57', 'Dez|2027-12-24|57'], $this->zeilen(['zeitraum' => 'jahr']));
    }

    public function testLeapDayBirthdayIsTheTwentyEighthInANormalYear(): void
    {
        $this->mitglied(1, 'Schalt', '2000-02-29');

        self::assertSame(['Schalt|2027-02-28|27'], $this->zeilen(['datum' => '2027-02-10']));
        self::assertSame(['Schalt|2028-02-29|28'], $this->zeilen(['datum' => '2028-02-10']));
        self::assertSame([], $this->zeilen(['zeitraum' => 'woche', 'datum' => '2027-03-01']));
        self::assertSame(['Schalt|2027-02-28|27'], $this->zeilen(['zeitraum' => 'woche', 'datum' => '2027-02-22']));
    }

    public function testFormerMembersAreLeftOutButFutureLeaversStay(): void
    {
        $this->mitglied(1, 'Weg', '1980-03-05', '2027-03-01');
        $this->mitglied(2, 'Geht', '1980-03-06', '2027-04-01');

        self::assertSame(['Geht|2027-03-06|47'], $this->zeilen([]));
    }

    public function testNavigationLeadsToTheNeighbouringPeriods(): void
    {
        $e = $this->liste->ergebnis(['zeitraum' => 'monat', 'datum' => '', 'nur_runde' => false], new Konto(1, 'A', Rolle::Vorstand));

        self::assertSame('März 2027', $e->navigation?->titel);
        self::assertSame(['datum' => '2027-02-01'], $e->navigation->zurueck);
        self::assertSame(['datum' => '2027-04-01'], $e->navigation->vor);
    }

    public function testViewAndFileNameFollowThePeriod(): void
    {
        $this->mitglied(1, 'Eins', '1990-03-05');
        $ansicht = ListenAnsicht::aus($this->liste, ['zeitraum' => 'jahr', 'datum' => 'kaputt'], new Konto(1, 'A', Rolle::Vorstand));

        self::assertCount(1, $ansicht->zeilen);
        self::assertSame('geburtstage-2027', $this->liste->dateiname($ansicht->filter));
        self::assertSame('geburtstage-2027-03', $this->liste->dateiname(['zeitraum' => 'monat', 'datum' => '']));
        self::assertSame('geburtstage-2027-03-08-woche', $this->liste->dateiname(['zeitraum' => 'woche', 'datum' => '']));
    }
}
