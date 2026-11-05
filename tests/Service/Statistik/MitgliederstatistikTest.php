<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistik;

use App\Domain\Abteilung;
use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Mitglied;
use App\Service\Statistik\Altersgruppen;
use App\Service\Statistik\Auswertung;
use App\Service\Statistik\AuswertungsZeile;
use App\Service\Statistik\Mitgliederstatistik;
use App\Service\Statistik\Mitgliederzahlen;
use PHPUnit\Framework\TestCase;

/**
 * "Statistik – Mitglieder" (docs/spec/09-listen-und-exporte.md section 7):
 * the age bounds, the key date, former members counted nowhere but in the
 * exits. Members are made up.
 */
final class MitgliederstatistikTest extends TestCase
{
    private const string HEUTE = '2026-11-05';

    private static function heute(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::HEUTE);
    }

    /** @param list<int> $abteilungen */
    private static function mitglied(
        int $id,
        ?string $geburt = '1980-01-01',
        ?Geschlecht $geschlecht = null,
        ?string $eintritt = '2010-01-01',
        ?string $austritt = null,
        bool $aktiv = true,
        string $ort = 'Musterstadt',
        string $plz = '12345',
        array $abteilungen = [],
        ?int $familie = null,
    ): Mitglied {
        return new Mitglied(
            $id,
            'Muster' . $id,
            geschlecht: $geschlecht,
            geburtsdatum: $geburt === null ? null : new \DateTimeImmutable($geburt),
            plz: $plz,
            ort: $ort,
            eintrittsdatum: $eintritt === null ? null : new \DateTimeImmutable($eintritt),
            austrittsdatum: $austritt === null ? null : new \DateTimeImmutable($austritt),
            aktiv: $aktiv,
            abteilungIds: $abteilungen,
            familieId: $familie,
            familieRolle: $familie === null ? null : FamilienRolle::Kind,
            id: $id,
        );
    }

    /**
     * @param list<Mitglied> $mitglieder
     * @param list<Abteilung> $abteilungen
     */
    private static function zahlen(array $mitglieder, array $abteilungen = []): Mitgliederzahlen
    {
        return Mitgliederstatistik::berechnen(self::heute(), $mitglieder, $abteilungen);
    }

    private static function auswertung(Mitgliederzahlen $zahlen, string $id): Auswertung
    {
        foreach ($zahlen->auswertungen as $auswertung) {
            if ($auswertung->id === $id) {
                return $auswertung;
            }
        }
        self::fail('no evaluation ' . $id);
    }

    /** @return array<string, list<int>> */
    private static function tabelle(Auswertung $auswertung): array
    {
        $tabelle = [];
        foreach ($auswertung->zeilen as $zeile) {
            $tabelle[$zeile->name] = $zeile->werte;
        }

        return $tabelle;
    }

    public function testTheAgeGroupBoundsAreInclusive(): void
    {
        self::assertSame('0–6', Altersgruppen::gruppe(0));
        self::assertSame('0–6', Altersgruppen::gruppe(-1), 'born after the key date');
        self::assertSame('0–6', Altersgruppen::gruppe(6));
        self::assertSame('7–14', Altersgruppen::gruppe(7));
        self::assertSame('7–14', Altersgruppen::gruppe(14));
        self::assertSame('15–18', Altersgruppen::gruppe(15));
        self::assertSame('15–18', Altersgruppen::gruppe(18), '18 still belongs to 15–18');
        self::assertSame('19–26', Altersgruppen::gruppe(19));
        self::assertSame('27–40', Altersgruppen::gruppe(40));
        self::assertSame('41–60', Altersgruppen::gruppe(41));
        self::assertSame('61–70', Altersgruppen::gruppe(70));
        self::assertSame('71 und älter', Altersgruppen::gruppe(71));
        self::assertSame('71 und älter', Altersgruppen::gruppe(104));
        self::assertSame(Altersgruppen::OHNE_GEBURTSDATUM, Altersgruppen::gruppe(null));

        self::assertSame('Kinder (0–13)', Altersgruppen::lebensphase(13));
        self::assertSame('Jugendliche (14–17)', Altersgruppen::lebensphase(14));
        self::assertSame('Jugendliche (14–17)', Altersgruppen::lebensphase(17));
        self::assertSame('Erwachsene (18–66)', Altersgruppen::lebensphase(18));
        self::assertSame('Erwachsene (18–66)', Altersgruppen::lebensphase(66));
        self::assertSame('Senioren (ab 67)', Altersgruppen::lebensphase(67));
    }

    public function testTheAgeCountsOnTheKeyDate(): void
    {
        $zahlen = self::zahlen([
            // Turns 18 tomorrow: still a minor, still 17.
            self::mitglied(1, '2008-11-06', Geschlecht::W),
            // Turned 18 today.
            self::mitglied(2, '2008-11-05', Geschlecht::M),
            self::mitglied(3, null),
        ]);

        self::assertSame(1, $zahlen->kennzahlen->minderjaehrig);
        self::assertSame('17,5', $zahlen->kennzahlen->durchschnittsalter(), 'only members with a birth date');
        $gruppen = self::tabelle(self::auswertung($zahlen, 'altersgruppen'));
        self::assertSame([1, 1, 0, 0], $gruppen['15–18'], 'by gender: weiblich, männlich, divers, ohne Angabe');
        self::assertSame([0, 0, 0, 1], $gruppen[Altersgruppen::OHNE_GEBURTSDATUM]);
        self::assertCount(9, $gruppen, 'every group, empty ones too');
    }

    public function testFormerMembersCountNowhereButInTheExits(): void
    {
        $zahlen = self::zahlen([
            self::mitglied(1, geschlecht: Geschlecht::W, eintritt: '2020-03-01'),
            self::mitglied(2, geschlecht: Geschlecht::M, eintritt: '2020-05-01', austritt: '2024-12-31', ort: 'Beispieldorf'),
            // Leaving today: former from today on.
            self::mitglied(3, eintritt: '2019-01-01', austritt: self::HEUTE),
            // A noted exit in the future: still a member, no exit yet.
            self::mitglied(4, aktiv: false, eintritt: '2026-02-01', austritt: '2026-12-31'),
        ]);

        $k = $zahlen->kennzahlen;
        self::assertSame(2, $k->gesamt);
        self::assertSame(1, $k->aktiv);
        self::assertSame(1, $k->passiv);
        self::assertSame(1, $k->weiblich);
        self::assertSame(0, $k->maennlich);
        self::assertSame(1, $k->ohneGeschlecht);
        self::assertArrayNotHasKey('Beispieldorf', self::tabelle(self::auswertung($zahlen, 'wohnorte')));
        self::assertSame([2], self::auswertung($zahlen, 'wohnorte')->zusammen?->werte);

        $jahre = self::tabelle(self::auswertung($zahlen, 'ein-austritte'));
        self::assertCount(Mitgliederstatistik::JAHRE, $jahre);
        self::assertSame(['2017', '2026'], [(string) array_key_first($jahre), (string) array_key_last($jahre)]);
        self::assertSame([2, 0], $jahre['2020'], 'entries count the former members too');
        self::assertSame([1, 0], $jahre['2019']);
        self::assertSame([0, 1], $jahre['2024']);
        self::assertSame([1, 1], $jahre['2026'], 'the exit of today counts, the future one does not');
    }

    public function testTheLengthOfMembership(): void
    {
        self::assertSame('unter 5 Jahre', Mitgliederstatistik::dauerGruppe(new \DateTimeImmutable('2021-11-06'), self::heute()));
        self::assertSame('5 bis unter 10', Mitgliederstatistik::dauerGruppe(new \DateTimeImmutable('2021-11-05'), self::heute()));
        self::assertSame('10 bis unter 25', Mitgliederstatistik::dauerGruppe(new \DateTimeImmutable('2016-11-05'), self::heute()));
        self::assertSame('50 und mehr', Mitgliederstatistik::dauerGruppe(new \DateTimeImmutable('1976-11-05'), self::heute()));
        self::assertSame('40 bis unter 50', Mitgliederstatistik::dauerGruppe(new \DateTimeImmutable('1976-11-06'), self::heute()));
        self::assertSame('unter 5 Jahre', Mitgliederstatistik::dauerGruppe(new \DateTimeImmutable('2027-01-01'), self::heute()), 'a future entry');
        self::assertSame(Mitgliederstatistik::DAUER_UNBEKANNT, Mitgliederstatistik::dauerGruppe(null, self::heute()));
    }

    public function testPlacesShowTheTopTenAndTheRest(): void
    {
        $mitglieder = [];
        for ($i = 1; $i <= 12; $i++) {
            $mitglieder[] = self::mitglied($i, ort: 'Ort ' . chr(64 + $i), plz: sprintf('%05d', $i));
        }
        $mitglieder[] = self::mitglied(13, ort: 'ort a ', plz: '00001');
        $mitglieder[] = self::mitglied(14, ort: '', plz: '');

        $zahlen = self::zahlen($mitglieder);
        $orte = self::auswertung($zahlen, 'wohnorte');
        $namen = array_map(static fn(AuswertungsZeile $z): string => $z->name, $orte->zeilen);

        self::assertSame('Ort A', $namen[0], 'grouped case-insensitively, the first spelling names it');
        self::assertSame([2], $orte->zeilen[0]->werte);
        self::assertCount(12, $namen);
        self::assertSame('Sonstige (2 Orte)', $namen[10]);
        self::assertSame([2], $orte->zeilen[10]->werte);
        self::assertTrue($orte->zeilen[10]->ohne);
        self::assertSame('ohne Angabe', $namen[11]);
        self::assertSame(14, array_sum(array_map(static fn(AuswertungsZeile $z): int => $z->summe(), $orte->zeilen)));
        self::assertSame('00001', self::auswertung($zahlen, 'plz')->zeilen[0]->name, 'postcodes keep their leading zeros');
    }

    public function testDepartmentsCountEveryMembershipWithTheirAverageAge(): void
    {
        $abteilungen = [new Abteilung('Fußball', id: 1), new Abteilung('Turnen', id: 2), new Abteilung('Alt', aktiv: false, id: 3)];
        $zahlen = self::zahlen([
            self::mitglied(1, '1996-01-01', Geschlecht::W, abteilungen: [1, 2]),
            self::mitglied(2, '2006-01-01', Geschlecht::M, abteilungen: [1]),
            self::mitglied(3, null, abteilungen: []),
            self::mitglied(4, austritt: '2020-01-01', abteilungen: [3]),
        ], $abteilungen);

        $abteilung = self::auswertung($zahlen, 'abteilungen');
        self::assertNull($abteilung->zusammen, 'overlapping rows have no total');
        $zeilen = [];
        foreach ($abteilung->zeilen as $zeile) {
            $zeilen[$zeile->name] = [$zeile->werte, $zeile->zusatz];
        }
        self::assertSame([[[1, 1, 0, 0], '25,0'], [[1, 0, 0, 0], '30,0'], [[0, 0, 0, 1], '–']], array_values($zeilen));
        self::assertSame(['Fußball', 'Turnen', 'ohne Abteilung'], array_keys($zeilen), 'an inactive department without members is left out');
    }

    public function testFamiliesAndSingles(): void
    {
        $zahlen = self::zahlen([
            self::mitglied(1, familie: 7),
            self::mitglied(2, familie: 7),
            self::mitglied(3),
            self::mitglied(4, familie: 8, austritt: '2020-01-01'),
        ]);

        $familien = self::auswertung($zahlen, 'familien');
        self::assertSame(['in einer Familie' => [2], 'einzeln' => [1]], self::tabelle($familien));
        self::assertStringStartsWith('1 Familie ', $familien->erklaerung);
        self::assertSame('66,7' . "\u{00A0}%", $familien->anteil(2));
    }
}
