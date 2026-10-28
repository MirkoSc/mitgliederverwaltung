<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Geld;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Money is integer cent (CLAUDE.md section 5) - in and out without float. */
final class GeldTest extends TestCase
{
    private const string NBSP = "\u{00A0}";

    /**
     * @return array<string, array{int, string}>
     */
    public static function anzeigen(): array
    {
        return [
            'null' => [0, '0,00'],
            'cent' => [5, '0,05'],
            'euro' => [1250, '12,50'],
            'tausender' => [123456, '1.234,56'],
            'millionen' => [123456789, '1.234.567,89'],
            'negativ' => [-1250, '-12,50'],
            'negativ cent' => [-5, '-0,05'],
            'groesste zahl' => [PHP_INT_MAX, '92.233.720.368.547.758,07'],
            'kleinste zahl' => [PHP_INT_MIN, '-92.233.720.368.547.758,08'],
        ];
    }

    #[DataProvider('anzeigen')]
    public function testShowsGermanNotationWithoutRoundingErrors(int $cent, string $betrag): void
    {
        self::assertSame($betrag . self::NBSP . '€', Geld::anzeigen($cent));
        self::assertSame($betrag . self::NBSP . '€', geld($cent), 'the template helper');
    }

    public function testTheTemplateHelperShowsNothingForNull(): void
    {
        self::assertSame('', geld(null));
    }

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function eingaben(): array
    {
        return [
            'ganze euro' => ['12', 1200],
            'komma eine stelle' => ['12,5', 1250],
            'komma zwei stellen' => ['12,50', 1250],
            'tausenderpunkt' => ['1.234,56', 123456],
            'tausender ohne nachkomma' => ['1.234', 123400],
            'dezimalpunkt' => ['1234.56', 123456],
            'mit eurozeichen' => ['12,50 €', 1250],
            'mit leerzeichen' => [' 1 234,00 ', 123400],
            'negativ' => ['-3,00', -300],
            'plus' => ['+3', 300],
            'fuehrende null' => ['0,05', 5],
            'nur null' => ['0', 0],
            'leer' => ['', null],
            'text' => ['zwölf', null],
            'drei nachkommastellen' => ['12,505', null],
            'zwei kommas' => ['1,2,3', null],
            'falsche gruppierung' => ['12.34.56', null],
            'komma ohne ziffern' => ['12,', null],
            'nur vorzeichen' => ['-', null],
            'zu gross' => ['1234567890123456', null],
            'float schreibweise' => ['1e3', null],
        ];
    }

    #[DataProvider('eingaben')]
    public function testReadsWhatPeopleType(string $eingabe, ?int $cent): void
    {
        self::assertSame($cent, Geld::ausEingabe($eingabe));
    }

    public function testFormValuesReadBackUnchanged(): void
    {
        foreach ([0, 5, 1250, 123456, -300] as $cent) {
            self::assertSame($cent, Geld::ausEingabe(Geld::eingabeWert($cent)), (string) $cent);
            self::assertSame($cent, Geld::ausEingabe(Geld::anzeigen($cent)), (string) $cent);
        }
    }
}
