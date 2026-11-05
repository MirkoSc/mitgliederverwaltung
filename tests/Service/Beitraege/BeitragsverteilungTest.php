<?php

declare(strict_types=1);

namespace App\Tests\Service\Beitraege;

use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\FamilienRolle;
use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Service\Beitraege\Beitragsverteilung;
use App\Service\Beitraege\Verteilung;
use App\Service\Beitraege\VerteilungsZeile;
use PHPUnit\Framework\TestCase;

/**
 * "Verteilung der Beiträge" (docs/spec/08-beitraege.md section 2b): the
 * projection, the created fees, the split over departments and families -
 * the department sums always add up to the total. Members are made up.
 */
final class BeitragsverteilungTest extends TestCase
{
    private const int FUSSBALL = 1;
    private const int GYMNASTIK = 2;
    private const int TENNIS = 3;
    private const int ALT = 4;

    private const int AKTIV = 10;
    private const int PASSIV = 11;
    private const int FAMILIE = 12;
    private const int FREI = 13;

    /** @return list<Beitragsart> */
    private static function arten(): array
    {
        return [
            new Beitragsart('Aktiv', 8500, id: self::AKTIV),
            new Beitragsart('Passiv', 5000, id: self::PASSIV),
            new Beitragsart('Familie', 13000, id: self::FAMILIE),
            new Beitragsart('Beitragsfrei', 0, id: self::FREI),
        ];
    }

    /** @return list<Abteilung> */
    private static function abteilungen(): array
    {
        return [
            new Abteilung('Fußball', id: self::FUSSBALL),
            new Abteilung('Gymnastik', id: self::GYMNASTIK),
            new Abteilung('Tennis', id: self::TENNIS),
            new Abteilung('Alte Abteilung', aktiv: false, id: self::ALT),
        ];
    }

    /**
     * @param list<int> $abteilungen
     */
    private static function mitglied(int $id, ?int $art, array $abteilungen = [], ?string $eintritt = '2010-01-01', ?string $austritt = null, ?int $familie = null, ?FamilienRolle $rolle = null): Mitglied
    {
        return new Mitglied(
            $id,
            'Muster' . $id,
            eintrittsdatum: $eintritt === null ? null : new \DateTimeImmutable($eintritt),
            austrittsdatum: $austritt === null ? null : new \DateTimeImmutable($austritt),
            abteilungIds: $abteilungen,
            beitragsartId: $art,
            familieId: $familie,
            familieRolle: $rolle,
            id: $id,
        );
    }

    /** @return list<Mitglied> */
    private static function verein(): array
    {
        return [
            self::mitglied(1, self::AKTIV, [self::FUSSBALL]),
            self::mitglied(2, self::AKTIV, [self::FUSSBALL, self::GYMNASTIK]),
            self::mitglied(3, self::PASSIV),
            // Former on 1 January: no fee.
            self::mitglied(4, self::AKTIV, [self::FUSSBALL], austritt: '2025-06-30'),
            // Joined in May: 8/12 of 85 € = 56,67 €.
            self::mitglied(5, self::AKTIV, [self::FUSSBALL], eintritt: '2026-05-15'),
            // Joins after the year: no fee.
            self::mitglied(6, self::AKTIV, [self::FUSSBALL], eintritt: '2027-02-01'),
            // A family: the payer and two without a fee of their own.
            self::mitglied(7, self::FAMILIE, [self::FUSSBALL], familie: 1, rolle: FamilienRolle::Zahler),
            self::mitglied(8, null, [self::FUSSBALL], familie: 1, rolle: FamilienRolle::Kind),
            self::mitglied(9, null, [self::GYMNASTIK], familie: 1, rolle: FamilienRolle::Elternteil),
            self::mitglied(10, self::FREI, [self::TENNIS]),
        ];
    }

    /** @return array<string, array{int, int}> name => [anzahl, summe] */
    private static function zeilen(array $zeilen): array
    {
        $ergebnis = [];
        foreach ($zeilen as $zeile) {
            self::assertInstanceOf(VerteilungsZeile::class, $zeile);
            $ergebnis[$zeile->name] = [$zeile->anzahl, $zeile->summeCent];
        }

        return $ergebnis;
    }

    private static function abteilungenGleichGesamt(Verteilung $v): void
    {
        self::assertSame($v->summeCent, array_sum(array_map(static fn(VerteilungsZeile $z): int => $z->summeCent, $v->nachAbteilung)));
        self::assertSame($v->summeCent, array_sum(array_map(static fn(VerteilungsZeile $z): int => $z->summeCent, $v->nachArt)));
    }

    public function testWithoutCreatedFeesTheYearIsProjectedLikeCreatingWould(): void
    {
        $v = Beitragsverteilung::berechnen(2026, self::verein(), self::arten(), self::abteilungen(), []);

        self::assertTrue($v->hochgerechnet);
        self::assertSame(5, $v->anzahl);
        self::assertSame(8500 + 8500 + 5000 + 5667 + 13000, $v->summeCent);
        self::assertSame([
            'Aktiv' => [3, 8500 + 8500 + 5667],
            'Passiv' => [1, 5000],
            'Familie' => [1, 13000],
        ], self::zeilen($v->nachArt));
        self::abteilungenGleichGesamt($v);
    }

    public function testAFeeIsSplitOverDepartmentsAndAFamilyFeeOverTheFamily(): void
    {
        $v = Beitragsverteilung::berechnen(2026, self::verein(), self::arten(), self::abteilungen(), []);

        // Fußball: 1 whole, half of 2, 5 whole, two thirds of the family (7 and 8).
        // Gymnastik: half of 2, a third of the family (9). Tennis: only a free member.
        // The inactive department without anything does not show.
        self::assertSame([
            'Fußball' => [5, 8500 + 4250 + 5667 + 8667],
            'Gymnastik' => [2, 4250 + 4333],
            'Tennis' => [0, 0],
            'ohne Abteilung' => [1, 5000],
        ], self::zeilen($v->nachAbteilung));
        self::assertTrue($v->nachAbteilung[3]->ohne);
        self::assertSame(7, $v->personen);
        self::abteilungenGleichGesamt($v);
    }

    public function testCreatedFeesCountWithTheirAmountsInEveryStatus(): void
    {
        $beitraege = [
            new Jahresbeitrag(1, 2026, 8500, JahresbeitragStatus::Bezahlt),
            new Jahresbeitrag(2, 2026, 6000),
            // A member whose fee type was removed after the fee was created.
            new Jahresbeitrag(11, 2026, 2500, JahresbeitragStatus::ImEinzug),
        ];
        $mitglieder = [...self::verein(), self::mitglied(11, null, [self::ALT])];

        $v = Beitragsverteilung::berechnen(2026, $mitglieder, self::arten(), self::abteilungen(), $beitraege);

        self::assertFalse($v->hochgerechnet);
        self::assertSame(3, $v->anzahl);
        self::assertSame(17000, $v->summeCent);
        self::assertSame(['Aktiv' => [2, 14500], 'ohne Beitragsart' => [1, 2500]], self::zeilen($v->nachArt));
        self::assertSame([
            'Fußball' => [2, 8500 + 3000],
            'Gymnastik' => [1, 3000],
            'Tennis' => [0, 0],
            'Alte Abteilung' => [1, 2500],
        ], self::zeilen($v->nachAbteilung));
        self::abteilungenGleichGesamt($v);
    }

    public function testAFamilyMemberWithAFeeOfTheirOwnIsNotPartOfTheFamilyFee(): void
    {
        $mitglieder = [
            self::mitglied(1, self::FAMILIE, [self::FUSSBALL], familie: 1, rolle: FamilienRolle::Zahler),
            self::mitglied(2, self::PASSIV, [self::GYMNASTIK], familie: 1, rolle: FamilienRolle::Kind),
            // Former: no longer part of the family's share.
            self::mitglied(3, null, [self::TENNIS], austritt: '2025-12-31', familie: 1, rolle: FamilienRolle::Kind),
        ];

        $v = Beitragsverteilung::berechnen(2026, $mitglieder, self::arten(), self::abteilungen(), []);

        self::assertSame(['Fußball' => [1, 13000], 'Gymnastik' => [1, 5000], 'Tennis' => [0, 0]], self::zeilen($v->nachAbteilung));
    }

    public function testNothingToCollect(): void
    {
        $v = Beitragsverteilung::berechnen(2026, [self::mitglied(1, self::FREI)], self::arten(), self::abteilungen(), []);

        self::assertSame(0, $v->anzahl);
        self::assertSame(0, $v->summeCent);
        self::assertSame([], $v->nachArt);
    }

    public function testSplittingKeepsEveryCent(): void
    {
        self::assertSame([1 => 34, 2 => 33, 3 => 33], Beitragsverteilung::teilen(100, [1 => 1, 2 => 1, 3 => 1]));
        self::assertSame([1 => 8667, 2 => 4333], Beitragsverteilung::teilen(13000, [1 => 2, 2 => 1]));
        self::assertSame([5 => 4250, 6 => 4250], Beitragsverteilung::teilen(8500, [5 => 1, 6 => 1]));
        self::assertSame([1 => 1, 2 => 0], Beitragsverteilung::teilen(1, [1 => 1, 2 => 1]));
    }
}
