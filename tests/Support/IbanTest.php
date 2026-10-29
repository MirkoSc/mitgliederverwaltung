<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** IBAN check digits (docs/spec/07-mitglieder.md section 1); made-up example IBANs. */
final class IbanTest extends TestCase
{
    public function testNormalisingRemovesSpacesAndUpperCases(): void
    {
        self::assertSame('DE89370400440532013000', Iban::normalisieren(' de89 3704 0044 0532 0130 00 '));
        self::assertSame('DE89370400440532013000', Iban::normalisieren("DE89-3704\t0044 0532 013000"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gueltige(): iterable
    {
        yield 'Germany' => ['DE89370400440532013000'];
        yield 'Germany, second example' => ['DE02120300000000202051'];
        yield 'with spaces and lower case' => ['de02 1203 0000 0000 2020 51'];
        yield 'Austria' => ['AT611904300234573201'];
        yield 'Netherlands' => ['NL91ABNA0417164300'];
        yield 'Great Britain, letters inside' => ['GB29NWBK60161331926819'];
    }

    #[DataProvider('gueltige')]
    public function testValidIbans(string $iban): void
    {
        self::assertTrue(Iban::istGueltig($iban));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ungueltige(): iterable
    {
        yield 'empty' => [''];
        yield 'one digit wrong' => ['DE89370400440532013001'];
        yield 'two digits swapped' => ['DE89370400440523013000'];
        yield 'wrong check digits' => ['DE88370400440532013000'];
        yield 'too short for Germany' => ['DE8937040044053201300'];
        yield 'too long for Germany' => ['DE893704004405320130000'];
        yield 'no country' => ['8937040044053201300012'];
        yield 'special character' => ['DE89370400440532013.00'];
        yield 'umlaut' => ['DE89370400440532013ä00'];
    }

    #[DataProvider('ungueltige')]
    public function testInvalidIbans(string $iban): void
    {
        self::assertFalse(Iban::istGueltig($iban));
    }

    public function testDisplayInGroupsOfFour(): void
    {
        self::assertSame('DE02 1203 0000 0000 2020 51', Iban::anzeigen('de02120300000000202051'));
    }
}
