<?php

declare(strict_types=1);

namespace App\Tests\Listen;

use App\Listen\GeburtstageZeitraum;
use PHPUnit\Framework\TestCase;

final class GeburtstageZeitraumTest extends TestCase
{
    private function tag(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testTheWeekRunsFromMondayToSunday(): void
    {
        $z = GeburtstageZeitraum::um('woche', $this->tag('2027-03-03')); // a Wednesday

        self::assertSame('2027-03-01', $z->von->format('Y-m-d'));
        self::assertSame('2027-03-07', $z->bis->format('Y-m-d'));
        self::assertSame('2027-03-01', GeburtstageZeitraum::um('woche', $this->tag('2027-03-07'))->von->format('Y-m-d'));
    }

    public function testMonthAndYear(): void
    {
        $m = GeburtstageZeitraum::um('monat', $this->tag('2028-02-10'));
        self::assertSame('2028-02-29', $m->bis->format('Y-m-d'));
        self::assertSame('Februar 2028', $m->titel());

        $j = GeburtstageZeitraum::um('jahr', $this->tag('2028-02-10'));
        self::assertSame('2028-01-01', $j->von->format('Y-m-d'));
        self::assertSame('2028-12-31', $j->bis->format('Y-m-d'));
        self::assertSame('2028', $j->titel());
    }

    public function testNeighboursAcrossNewYear(): void
    {
        $woche = GeburtstageZeitraum::um('woche', $this->tag('2026-12-30')); // 28.12.2026 - 03.01.2027
        self::assertSame('2027-01-03', $woche->bis->format('Y-m-d'));
        self::assertSame('2027-01-04', $woche->nachbar(1)->von->format('Y-m-d'));
        self::assertSame('2026-12-21', $woche->nachbar(-1)->von->format('Y-m-d'));

        self::assertSame('2027-01-01', GeburtstageZeitraum::um('monat', $this->tag('2026-12-15'))->nachbar(1)->von->format('Y-m-d'));
        self::assertSame('2026-12-01', GeburtstageZeitraum::um('monat', $this->tag('2027-01-31'))->nachbar(-1)->von->format('Y-m-d'));
        self::assertSame('2026-01-01', GeburtstageZeitraum::um('jahr', $this->tag('2027-06-01'))->nachbar(-1)->von->format('Y-m-d'));
    }

    public function testMonthNeighbourFromThe31stDoesNotSkip(): void
    {
        self::assertSame('2027-03-01', GeburtstageZeitraum::um('monat', $this->tag('2027-01-31'))->nachbar(1)->nachbar(1)->von->format('Y-m-d'));
        self::assertSame('2027-02-01', GeburtstageZeitraum::um('monat', $this->tag('2027-01-31'))->nachbar(1)->von->format('Y-m-d'));
    }

    public function testAWeekOverNewYearHoldsBirthdaysOfBothYears(): void
    {
        $woche = GeburtstageZeitraum::um('woche', $this->tag('2026-12-30'));

        self::assertSame('2026-12-31', $woche->geburtstagIn($this->tag('1990-12-31'))?->format('Y-m-d'));
        self::assertSame('2027-01-02', $woche->geburtstagIn($this->tag('1990-01-02'))?->format('Y-m-d'));
        self::assertNull($woche->geburtstagIn($this->tag('1990-01-04')));
        self::assertNull($woche->geburtstagIn($this->tag('1990-12-27')));
        self::assertSame([12, 1], $woche->monate());
    }

    public function testLeapDayCountsAsTheTwentyEighthInOtherYears(): void
    {
        $geboren = $this->tag('2000-02-29');

        $feb2027 = GeburtstageZeitraum::um('woche', $this->tag('2027-02-28'));
        self::assertSame('2027-02-28', $feb2027->geburtstagIn($geboren)?->format('Y-m-d'));

        $feb2028 = GeburtstageZeitraum::um('woche', $this->tag('2028-02-28'));
        self::assertSame('2028-02-29', $feb2028->geburtstagIn($geboren)?->format('Y-m-d'));
        // In a leap year the 28th is no birthday.
        self::assertNull(GeburtstageZeitraum::um('woche', $this->tag('2028-02-21'))->geburtstagIn($geboren));
        self::assertNull(GeburtstageZeitraum::um('woche', $this->tag('2027-03-01'))->geburtstagIn($geboren));
    }
}
