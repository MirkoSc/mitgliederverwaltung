<?php

declare(strict_types=1);

namespace App\Tests\Listen;

use App\Listen\ListenFilter;
use PHPUnit\Framework\TestCase;

final class ListenFilterTest extends TestCase
{
    public function testASelectionTakesOnlyItsOptionsAndFallsBackToTheDefault(): void
    {
        $filter = ListenFilter::auswahl('zeitraum', 'Zeitraum', ['woche' => 'Woche', 'monat' => 'Monat', 'jahr' => 'Jahr'], 'monat');

        self::assertSame('jahr', $filter->lesen(['zeitraum' => 'jahr']));
        self::assertSame('monat', $filter->lesen(['zeitraum' => 'tag']));
        self::assertSame('monat', $filter->lesen([]));
        self::assertSame('monat', $filter->lesen(['zeitraum' => ['jahr']]));
    }

    public function testTheFirstOptionIsTheDefaultUnlessAnotherIsNamed(): void
    {
        self::assertSame('woche', ListenFilter::auswahl('z', 'Z', ['woche' => 'W', 'monat' => 'M'])->standard);
        self::assertSame('1', ListenFilter::auswahl('z', 'Z', [1 => 'Eins', 2 => 'Zwei'])->lesen(['z' => '3']), 'numeric keys work as text');
        self::assertSame('2', ListenFilter::auswahl('z', 'Z', [1 => 'Eins', 2 => 'Zwei'])->lesen(['z' => '2']));
    }

    public function testACheckboxIsTrueOnlyWhenChecked(): void
    {
        $filter = ListenFilter::haken('nur_runde', 'Nur runde');

        self::assertTrue($filter->lesen(['nur_runde' => '1']));
        self::assertTrue($filter->lesen(['nur_runde' => 'on']));
        self::assertFalse($filter->lesen(['nur_runde' => '0']));
        self::assertFalse($filter->lesen(['nur_runde' => '']));
        self::assertFalse($filter->lesen([]));
    }

    public function testAYearMustBeFourDigitsInRange(): void
    {
        $filter = ListenFilter::jahr('jahr', 'Jahr', 2027);

        self::assertSame(2030, $filter->lesen(['jahr' => '2030']));
        self::assertSame(2027, $filter->lesen(['jahr' => '30']));
        self::assertSame(2027, $filter->lesen(['jahr' => '1800']));
        self::assertSame(2027, $filter->lesen(['jahr' => '99999']));
        self::assertSame(2027, $filter->lesen(['jahr' => 'zweitausend']));
        self::assertSame(2027, $filter->lesen([]));
    }

    public function testADateIsKeptAsIsoDayAndAnInvalidOneFallsBack(): void
    {
        $filter = ListenFilter::datum('stichtag', 'Stichtag', '2027-01-01');

        self::assertSame('2027-06-30', $filter->lesen(['stichtag' => '2027-06-30']));
        self::assertSame('2027-06-30', $filter->lesen(['stichtag' => '30.06.2027']));
        self::assertSame('2027-01-01', $filter->lesen(['stichtag' => '2027-02-30']));
        self::assertSame('2027-01-01', $filter->lesen(['stichtag' => 'gestern']));
        self::assertSame('', ListenFilter::datum('s', 'S')->lesen([]));
    }

    public function testNamesOfThePageAreNotAllowedAsFilterNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ListenFilter::haken('seite', 'Seite');
    }

    public function testASelectionWithoutOptionsOrWithAnUnknownDefaultIsRejected(): void
    {
        try {
            ListenFilter::auswahl('z', 'Z', []);
            self::fail('no options');
        } catch (\InvalidArgumentException) {
            $this->expectException(\InvalidArgumentException::class);
            ListenFilter::auswahl('z', 'Z', ['a' => 'A'], 'b');
        }
    }
}
