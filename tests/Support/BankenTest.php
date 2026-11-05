<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Banken;
use App\Support\Bic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BIC shape and the German banks by Bankleitzahl (docs/spec/07-mitglieder.md
 * section 1). The IBANs are the usual made-up examples; the banks behind
 * their Bankleitzahlen are real and public (Bundesbank data).
 */
final class BankenTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function bics(): iterable
    {
        yield '11 characters' => ['COBADEFFXXX', true];
        yield '8 characters' => ['COBADEFF', true];
        yield 'digits in location and branch' => ['GENODEF1P12', true];
        yield 'spaces and lower case' => [' coba de ff xxx ', true];
        yield 'empty' => ['', false];
        yield 'too short' => ['COBADE', false];
        yield '9 characters' => ['COBADEFFX', false];
        yield 'digit in the country' => ['COBAD1FFXXX', false];
        yield 'digit in the bank code' => ['C0BADEFFXXX', false];
    }

    #[DataProvider('bics')]
    public function testBicShape(string $bic, bool $gueltig): void
    {
        self::assertSame($gueltig, Bic::istGueltig($bic));
    }

    public function testEightAndElevenCharactersWithXxxAreTheSame(): void
    {
        self::assertTrue(Bic::gleich('COBADEFF', 'cobadeffxxx'));
        self::assertTrue(Bic::gleich('HYVEDEMM488', 'HYVEDEMM488'));
        self::assertFalse(Bic::gleich('HYVEDEMM', 'HYVEDEMM488'), 'a branch is not the head office');
        self::assertFalse(Bic::gleich('COBADEFFXXX', 'INGDDEFFXXX'));
    }

    public function testTheBankOfAGermanIban(): void
    {
        $bank = Banken::fuerIban('de89 3704 0044 0532 0130 00');

        self::assertNotNull($bank);
        self::assertSame('37040044', $bank->blz);
        self::assertSame('Commerzbank', $bank->name);
        self::assertSame('COBADEFFXXX', $bank->bic);
        self::assertSame('BYLADEM1001', Banken::fuerIban('DE02120300000000202051')?->bic);
    }

    public function testNoBankForOtherIbans(): void
    {
        self::assertNull(Banken::fuerIban(''));
        self::assertNull(Banken::fuerIban('DE00370400440532013000'), 'wrong check digits');
        self::assertNull(Banken::fuerIban('AT611904300234573201'), 'not German');
        self::assertNull(Banken::fuerBlz('00000000'));
        self::assertNull(Banken::fuerBlz('3704004'), 'seven digits');
        self::assertNull(Banken::fuerBlz('99999999'), 'unknown');
    }

    public function testWhetherTheBicFitsTheIban(): void
    {
        self::assertTrue(Banken::bicPasst('COBADEFF', 'DE89370400440532013000'));
        self::assertFalse(Banken::bicPasst('INGDDEFFXXX', 'DE89370400440532013000'));
        self::assertNull(Banken::bicPasst('', 'DE89370400440532013000'), 'no BIC');
        self::assertNull(Banken::bicPasst('INGDDEFFXXX', 'AT611904300234573201'), 'not German');
        self::assertNull(Banken::bicPasst('INGDDEFFXXX', 'DE00370400440532013000'), 'IBAN wrong');
    }

    public function testBicHint(): void
    {
        $iban = 'DE89370400440532013000';
        self::assertNull(Banken::bicFehler('', $iban));
        self::assertNull(Banken::bicFehler('COBADEFFXXX', $iban));
        self::assertStringContainsString('nicht die richtige Form', (string) Banken::bicFehler('XYZ', $iban));
        self::assertStringContainsString('gehört COBADEFFXXX.', (string) Banken::bicFehler('INGDDEFFXXX', $iban));
        self::assertNull(Banken::bicFehler('INGDDEFFXXX', 'AT611904300234573201'), 'not German: cannot be told');
    }

    public function testAnEmptyBicIsFilledIn(): void
    {
        self::assertSame('COBADEFFXXX', Banken::bicErgaenzen('', 'DE89370400440532013000'));
        self::assertSame('INGDDEFFXXX', Banken::bicErgaenzen(' ingd deff xxx', 'DE89370400440532013000'), 'a BIC given stays');
        self::assertSame('', Banken::bicErgaenzen('', 'AT611904300234573201'));
        self::assertSame('', Banken::bicErgaenzen('', 'DE00370400440532013000'));
        self::assertSame('', Banken::bicErgaenzen('', ''));
    }
}
