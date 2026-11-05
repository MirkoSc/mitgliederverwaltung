<?php

declare(strict_types=1);

namespace App\Tests\Service\Statistik;

use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Domain\Zahlart;
use App\Service\Statistik\Auswertung;
use App\Service\Statistik\AuswertungsZeile;
use App\Service\Statistik\Beitragsstatistik;
use App\Service\Statistik\Beitragszahlen;
use App\Service\Statistik\Diagramm;
use PHPUnit\Framework\TestCase;

/**
 * "Statistik – Beiträge" (docs/spec/09-listen-und-exporte.md section 7):
 * every evaluation adds up to the total to the cent, a family fee is
 * split over the family before the features, former members count only
 * through a fee of the year. Members are made up.
 */
final class BeitragsstatistikTest extends TestCase
{
    private const int AKTIV = 10;
    private const int FAMILIE = 12;

    /** @return list<Beitragsart> */
    private static function arten(): array
    {
        return [new Beitragsart('Aktiv', 8500, id: self::AKTIV), new Beitragsart('Familie', 10000, id: self::FAMILIE)];
    }

    /** @return list<Abteilung> */
    private static function abteilungen(): array
    {
        return [new Abteilung('Fußball', id: 1), new Abteilung('Turnen', id: 2)];
    }

    private static function mitglied(
        int $id,
        ?int $art = self::AKTIV,
        ?string $geburt = '1980-06-01',
        ?Geschlecht $geschlecht = null,
        string $ort = 'Musterstadt',
        bool $aktiv = true,
        ?int $familie = null,
        ?FamilienRolle $rolle = null,
        Zahlart $zahlart = Zahlart::Lastschrift,
        ?string $austritt = null,
    ): Mitglied {
        return new Mitglied(
            $id,
            'Muster' . $id,
            geschlecht: $geschlecht,
            geburtsdatum: $geburt === null ? null : new \DateTimeImmutable($geburt),
            ort: $ort,
            eintrittsdatum: new \DateTimeImmutable('2010-01-01'),
            austrittsdatum: $austritt === null ? null : new \DateTimeImmutable($austritt),
            aktiv: $aktiv,
            abteilungIds: [1],
            beitragsartId: $art,
            zahlart: $zahlart,
            familieId: $familie,
            familieRolle: $rolle,
            id: $id,
        );
    }

    /** @return list<Mitglied> */
    private static function verein(): array
    {
        return [
            self::mitglied(1, geschlecht: Geschlecht::W, geburt: '1950-03-01', ort: 'Beispieldorf', zahlart: Zahlart::Selbst),
            // A family of three: the payer's 100 € is split 33,34 / 33,33 / 33,33.
            self::mitglied(2, self::FAMILIE, '1985-01-01', Geschlecht::M, familie: 5, rolle: FamilienRolle::Zahler),
            self::mitglied(3, null, '2014-06-01', Geschlecht::W, familie: 5, rolle: FamilienRolle::Kind),
            self::mitglied(4, null, '2012-01-01', Geschlecht::D, familie: 5, rolle: FamilienRolle::Kind, aktiv: false),
            self::mitglied(5, geschlecht: null, geburt: null),
            // Left before the year: no fee, counts nowhere.
            self::mitglied(6, geschlecht: Geschlecht::M, ort: 'Anderswo', austritt: '2025-06-30'),
        ];
    }

    private static function zahlen(array $beitraege = []): Beitragszahlen
    {
        return Beitragsstatistik::berechnen(2026, self::verein(), self::arten(), self::abteilungen(), $beitraege);
    }

    /** @return array<string, int> name => cent */
    private static function summen(Beitragszahlen $zahlen, string $id): array
    {
        foreach ($zahlen->auswertungen as $auswertung) {
            if ($auswertung->id === $id) {
                $summen = [];
                foreach ($auswertung->zeilen as $zeile) {
                    $summen[$zeile->name] = $zeile->summe();
                }

                return $summen;
            }
        }
        self::fail('no evaluation ' . $id);
    }

    public function testEveryEvaluationAddsUpToTheTotalToTheCent(): void
    {
        $zahlen = self::zahlen();

        self::assertTrue($zahlen->verteilung->hochgerechnet);
        self::assertSame(8500 + 10000 + 8500, $zahlen->verteilung->summeCent);
        self::assertSame(['beitragsart', 'abteilung', 'geschlecht', 'altersgruppe', 'wohnort', 'aktiv', 'familie', 'zahlart'], array_map(
            static fn(Auswertung $a): string => $a->id,
            $zahlen->auswertungen,
        ));
        foreach ($zahlen->auswertungen as $auswertung) {
            self::assertSame($zahlen->verteilung->summeCent, array_sum(array_map(static fn(AuswertungsZeile $z): int => $z->summe(), $auswertung->zeilen)), $auswertung->id);
            self::assertSame([$zahlen->verteilung->summeCent], $auswertung->zusammen?->werte, $auswertung->id);
            self::assertTrue($auswertung->geld);
        }
    }

    public function testAFamilyFeeIsSplitOverThePeopleBehindIt(): void
    {
        $zahlen = self::zahlen();

        // 1: 85 € (w); 2: 33,34 € (m); 3: 33,33 € (w); 4: 33,33 € (d); 5: 85 € (ohne Angabe).
        self::assertSame(['weiblich' => 8500 + 3333, 'männlich' => 3334, 'divers' => 3333, 'ohne Angabe' => 8500], self::summen($zahlen, 'geschlecht'));
        self::assertSame(['aktiv' => 8500 + 3334 + 3333 + 8500, 'passiv' => 3333], self::summen($zahlen, 'aktiv'));
        self::assertSame(['in einer Familie' => 10000, 'einzeln' => 17000], self::summen($zahlen, 'familie'));

        $alter = self::summen($zahlen, 'altersgruppe');
        self::assertSame(3333 + 3333, $alter['7–14'], 'the children are 11 and 14 on 1 January 2026');
        self::assertSame(3334, $alter['41–60']);
        self::assertSame(8500, $alter['71 und älter']);
        self::assertSame(8500, $alter['ohne Geburtsdatum']);
        self::assertSame(0, $alter['0–6'], 'fixed groups stay, with 0');

        self::assertSame(['Musterstadt' => 18500, 'Beispieldorf' => 8500], self::summen($zahlen, 'wohnort'));
    }

    public function testTheZahlartIsThePayersAndNotSplit(): void
    {
        self::assertSame(['Lastschrift' => 18500, 'Selbstzahler' => 8500], self::summen(self::zahlen(), 'zahlart'));
    }

    public function testCreatedFeesCountWithTheirAmountEvenOfAFormerMember(): void
    {
        // Created on 1 January for member 6 (then still a member) - it stays in the year.
        $zahlen = self::zahlen([new Jahresbeitrag(1, 2026, 1001), new Jahresbeitrag(6, 2026, 999)]);

        self::assertFalse($zahlen->verteilung->hochgerechnet);
        self::assertSame(2000, $zahlen->verteilung->summeCent);
        self::assertSame(['weiblich' => 1001, 'männlich' => 999, 'divers' => 0], self::summen($zahlen, 'geschlecht'));
        self::assertSame(['Beispieldorf' => 1001, 'Anderswo' => 999], self::summen($zahlen, 'wohnort'));
    }

    public function testAFeeOfAnUnknownMemberGetsItsOwnRow(): void
    {
        $zahlen = self::zahlen([new Jahresbeitrag(99, 2026, 500), new Jahresbeitrag(1, 2026, 1000)]);

        $geschlecht = self::summen($zahlen, 'geschlecht');
        self::assertSame(500, $geschlecht[Beitragsstatistik::UNBEKANNT]);
        self::assertSame(1500, array_sum($geschlecht));
        self::assertSame(1500, array_sum(self::summen($zahlen, 'zahlart')));
    }

    public function testTheShareIsRoundedToATenthOfAPercent(): void
    {
        $a = new Auswertung('x', 'X', 'X', ['Summe'], [], Diagramm::Balken, new AuswertungsZeile('Zusammen', [30000]));

        self::assertSame(333, $a->promille(10000));
        self::assertSame('33,3' . "\u{00A0}%", $a->anteil(10000));
        self::assertSame('100,0' . "\u{00A0}%", $a->anteil(30000));
        self::assertSame('0,1' . "\u{00A0}%", $a->anteil(15));
        self::assertSame('0,0' . "\u{00A0}%", (new Auswertung('x', 'X', 'X', ['Summe'], [], Diagramm::Balken))->anteil(0));
    }
}
