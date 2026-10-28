<?php

declare(strict_types=1);

namespace App\Tests\Service\Verein;

use App\Service\Verein\Iban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IbanTest extends TestCase
{
    public function testNormalisingRemovesSeparatorsAndUpperCases(): void
    {
        self::assertSame('DE89370400440532013000', Iban::normalisieren(' de89 3704-0044 0532 0130 00 '));
    }

    #[DataProvider('gueltige')]
    public function testValidIbans(string $iban): void
    {
        self::assertTrue(Iban::istGueltig($iban));
    }

    /** @return iterable<string, array{string}> */
    public static function gueltige(): iterable
    {
        yield 'DE' => ['DE89370400440532013000'];
        yield 'AT' => ['AT611904300234573201'];
        yield 'GB with letters' => ['GB82WEST12345698765432'];
    }

    #[DataProvider('ungueltige')]
    public function testInvalidIbans(string $iban): void
    {
        self::assertFalse(Iban::istGueltig($iban));
    }

    /** @return iterable<string, array{string}> */
    public static function ungueltige(): iterable
    {
        yield 'wrong check digits' => ['DE89370400440532013001'];
        yield 'too short' => ['DE89'];
        yield 'empty' => [''];
        yield 'not normalised' => ['DE89 3704 0044 0532 0130 00'];
    }
}
