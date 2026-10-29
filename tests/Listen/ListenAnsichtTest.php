<?php

declare(strict_types=1);

namespace App\Tests\Listen;

use App\Domain\Konto;
use App\Domain\Rolle;
use App\Listen\ListenAnsicht;
use App\Listen\Spalte;
use App\Tests\Fixtures\Listen\BeispielListe;
use PHPUnit\Framework\TestCase;

final class ListenAnsichtTest extends TestCase
{
    private static function admin(): Konto
    {
        return new Konto(1, 'Admin', Rolle::Admin);
    }

    /** @return list<string> */
    private static function namen(ListenAnsicht $ansicht): array
    {
        return array_map(static fn($z): string => (string) $z->werte['name'], $ansicht->zeilen);
    }

    public function testFiltersAreReadValidatedAndHandedToTheList(): void
    {
        $ansicht = ListenAnsicht::aus(new BeispielListe(), ['gruppe' => 'a', 'nur_gerade' => '1', 'jahr' => 'kaputt'], self::admin());

        self::assertSame(['gruppe' => 'a', 'nur_gerade' => true, 'jahr' => 2027], $ansicht->filter);
        self::assertCount(60, $ansicht->zeilen);
    }

    public function testWithoutSortingTheListsOwnOrderStays(): void
    {
        $ansicht = ListenAnsicht::aus(new BeispielListe(), [], self::admin());

        self::assertNull($ansicht->sortierung);
        self::assertSame('Person 001', self::namen($ansicht)[0]);
    }

    public function testSortingAscendingAndDescendingByAColumn(): void
    {
        $auf = ListenAnsicht::aus(new BeispielListe(), ['sortierung' => 'name'], self::admin());
        $ab = ListenAnsicht::aus(new BeispielListe(), ['sortierung' => 'name', 'richtung' => 'ab'], self::admin());

        self::assertSame('name', $auf->sortierung);
        self::assertFalse($auf->absteigend);
        self::assertTrue($ab->absteigend);
        self::assertSame('Person 120', self::namen($ab)[0]);
        self::assertSame(array_reverse(self::namen($auf)), self::namen($ab));
    }

    public function testAnUnknownSortColumnIsIgnored(): void
    {
        $ansicht = ListenAnsicht::aus(new BeispielListe(), ['sortierung' => 'iban', 'richtung' => 'ab'], self::admin());

        self::assertNull($ansicht->sortierung);
        self::assertFalse($ansicht->absteigend);
        self::assertSame('Person 001', self::namen($ansicht)[0]);
    }

    public function testASortColumnTheAccountDoesNotGetIsIgnored(): void
    {
        // "beitrag" exists for the admin only (09: no fee columns for the board)
        $vorstand = new Konto(2, 'Vorstand', Rolle::Vorstand);

        $ansicht = ListenAnsicht::aus(new BeispielListe(), ['sortierung' => 'beitrag', 'richtung' => 'ab'], $vorstand);

        self::assertNull($ansicht->sortierung);
        self::assertCount(4, $ansicht->ergebnis->spalten);
    }

    public function testNumbersSortByValueDatesByDayAndTextWithUmlautsNextToTheirLetter(): void
    {
        $liste = new class extends BeispielListe {
            public function ergebnis(array $filter, Konto $konto): \App\Listen\ListenErgebnis
            {
                $z = static fn(array $w): \App\Listen\ListenZeile => new \App\Listen\ListenZeile($w);

                return new \App\Listen\ListenErgebnis(
                    [Spalte::text('name', 'Name'), Spalte::zahl('zahl', 'Zahl'), Spalte::datum('tag', 'Tag')],
                    [
                        $z(['name' => 'Nagel', 'zahl' => 10, 'tag' => '2027-01-02']),
                        $z(['name' => 'Müller', 'zahl' => 9, 'tag' => null]),
                        $z(['name' => 'Mueller', 'zahl' => null, 'tag' => '2026-12-31']),
                        $z(['name' => 'Ärzte', 'zahl' => 100, 'tag' => '2027-01-01']),
                        $z(['name' => '10', 'zahl' => 1, 'tag' => '2027-01-03']),
                        $z(['name' => '9', 'zahl' => 2, 'tag' => '']),
                    ],
                );
            }
        };

        $namen = static fn(array $q): array => array_map(static fn($r): string => (string) $r->werte['name'], ListenAnsicht::aus($liste, $q, self::admin())->zeilen);

        self::assertSame(['10', '9', 'Ärzte', 'Mueller', 'Müller', 'Nagel'], $namen(['sortierung' => 'name']), 'text: "10" before "9", an umlaut sorts as its base letter');
        self::assertSame(['10', '9', 'Müller', 'Nagel', 'Ärzte', 'Mueller'], $namen(['sortierung' => 'zahl']), 'numbers by value, empty last');
        self::assertSame(['Ärzte', 'Nagel', 'Müller', '9', '10', 'Mueller'], $namen(['sortierung' => 'zahl', 'richtung' => 'ab']), 'empty last when descending too');
        self::assertSame(['Mueller', 'Ärzte', 'Nagel', '10', 'Müller', '9'], $namen(['sortierung' => 'tag']), 'dates by day, empty last');
        self::assertSame(['10', 'Nagel', 'Ärzte', 'Mueller', 'Müller', '9'], $namen(['sortierung' => 'tag', 'richtung' => 'ab']), 'dates descending, empty last');
    }

    public function testPagingInPagesOfFifty(): void
    {
        $ansicht = ListenAnsicht::aus(new BeispielListe(), [], self::admin());

        self::assertSame(3, $ansicht->seitenzahl());
        self::assertCount(50, $ansicht->zeilenAufSeite(1));
        self::assertCount(20, $ansicht->zeilenAufSeite(3));
        self::assertSame('Person 051', $ansicht->zeilenAufSeite(2)[0]->werte['name']);
    }

    public function testThePageNumberIsClamped(): void
    {
        $ansicht = ListenAnsicht::aus(new BeispielListe(), [], self::admin());

        self::assertSame(1, $ansicht->seite(null));
        self::assertSame(1, $ansicht->seite('0'));
        self::assertSame(1, $ansicht->seite('-4'));
        self::assertSame(1, $ansicht->seite('abc'));
        self::assertSame(2, $ansicht->seite('2'));
        self::assertSame(3, $ansicht->seite('99'));
        self::assertSame(3, $ansicht->seite('99999999999'));
    }

    public function testAnEmptyListHasOnePage(): void
    {
        $leer = new class extends BeispielListe {
            public function ergebnis(array $filter, Konto $konto): \App\Listen\ListenErgebnis
            {
                return new \App\Listen\ListenErgebnis([Spalte::text('name', 'Name')], []);
            }
        };
        $ansicht = ListenAnsicht::aus($leer, [], self::admin());

        self::assertSame(1, $ansicht->seitenzahl());
        self::assertSame([], $ansicht->zeilenAufSeite(1));
    }

    public function testAResultNeedsUniqueColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \App\Listen\ListenErgebnis([Spalte::text('a', 'A'), Spalte::text('a', 'Nochmal')], []);
    }
}
