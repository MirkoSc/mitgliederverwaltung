<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Listen\ListenZeile;
use App\Listen\Spalte;
use App\Service\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    /** @param iterable<ListenZeile> $zeilen */
    private static function csv(array $spalten, iterable $zeilen): string
    {
        return implode('', iterator_to_array(CsvWriter::stuecke($spalten, $zeilen), false));
    }

    public function testFileStartsWithBomAndUsesSemicolonAndCrlf(): void
    {
        $csv = self::csv(
            [Spalte::text('name', 'Name'), Spalte::text('ort', 'Ort')],
            [new ListenZeile(['name' => 'Anna Muster', 'ort' => 'Musterstadt'])],
        );

        self::assertSame("\xEF\xBB\xBF" . "Name;Ort\r\nAnna Muster;Musterstadt\r\n", $csv);
    }

    public function testFieldsWithSemicolonQuoteOrLineBreakAreQuoted(): void
    {
        $csv = self::csv(
            [Spalte::text('a', 'A'), Spalte::text('b', 'B'), Spalte::text('c', 'C'), Spalte::text('d', 'D')],
            [new ListenZeile(['a' => 'x;y', 'b' => 'sagt "hallo"', 'c' => "zwei\nZeilen", 'd' => 'glatt'])],
        );

        self::assertSame("\xEF\xBB\xBFA;B;C;D\r\n\"x;y\";\"sagt \"\"hallo\"\"\";\"zwei\nZeilen\";glatt\r\n", $csv);
    }

    public function testATitleWithASemicolonIsQuotedToo(): void
    {
        self::assertStringStartsWith("\xEF\xBB\xBF\"Name; Vorname\"\r\n", self::csv([Spalte::text('n', 'Name; Vorname')], []));
    }

    public function testDatesAmountsAndNumbersAreWrittenTheGermanWay(): void
    {
        $csv = self::csv(
            [Spalte::datum('d', 'Datum'), Spalte::datum('e', 'Datum2'), Spalte::geld('g', 'Betrag'), Spalte::geld('h', 'Minus'), Spalte::zahl('z', 'Zahl')],
            [new ListenZeile(['d' => '2027-03-01', 'e' => new \DateTimeImmutable('2000-02-29 13:00'), 'g' => 1250, 'h' => -5, 'z' => 42])],
        );

        self::assertStringEndsWith("01.03.2027;29.02.2000;12,50;-0,05;42\r\n", $csv);
    }

    public function testMissingAndNullValuesAreEmptyCells(): void
    {
        $csv = self::csv(
            [Spalte::text('a', 'A'), Spalte::datum('b', 'B'), Spalte::text('c', 'C')],
            [new ListenZeile(['b' => null])],
        );

        self::assertStringEndsWith("A;B;C\r\n;;\r\n", $csv);
    }

    public function testAHeaderOnlyFileIsValidForAnEmptyList(): void
    {
        self::assertSame("\xEF\xBB\xBFName\r\n", self::csv([Spalte::text('name', 'Name')], []));
    }

    public function testUmlautsStayUtf8(): void
    {
        $csv = self::csv([Spalte::text('n', 'Straße')], [new ListenZeile(['n' => 'Müller'])]);

        self::assertStringContainsString("Straße\r\nMüller\r\n", $csv);
        self::assertTrue(mb_check_encoding($csv, 'UTF-8'));
    }
}
