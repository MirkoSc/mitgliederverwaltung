<?php

declare(strict_types=1);

namespace App\Tests\Service\Verein;

use App\Service\Verein\Vereinsdaten;
use PHPUnit\Framework\TestCase;

final class VereinsdatenTest extends TestCase
{
    /** @param array<string, string> $werte */
    private static function eingabe(array $werte): Vereinsdaten
    {
        return Vereinsdaten::ausEingabe(static fn(string $name): string => $werte[$name] ?? '');
    }

    public function testBankFieldsAreNormalised(): void
    {
        $daten = self::eingabe(['iban' => 'de89 3704 0044 0532 0130 00', 'bic' => ' coba de ff ', 'glaeubiger_id' => 'de98 zzz 0920 0000 1']);

        self::assertSame('DE89370400440532013000', $daten->wert('iban'));
        self::assertSame('COBADEFF', $daten->wert('bic'));
        self::assertSame('DE98ZZZ092000001', $daten->wert('glaeubiger_id'));
    }

    public function testAnInvalidDateIsDroppedAndReported(): void
    {
        $felder = ['freistellungsdatum' => '31.02.2020', 'mandatsdatum' => '2024-02-30'];
        $feld = static fn(string $name): string => $felder[$name] ?? '';
        $daten = Vereinsdaten::ausEingabe($feld);

        self::assertSame('', $daten->wert('freistellungsdatum'));
        self::assertSame('', $daten->wert('mandatsdatum'));
        self::assertSame(['Datum des letzten Freistellungsbescheids', 'Standard-Mandatsdatum'], Vereinsdaten::ungueltigeDaten($feld));
        self::assertSame('2024-03-01', self::eingabe(['mandatsdatum' => '2024-03-01'])->wert('mandatsdatum'));
    }

    public function testAnInvalidIbanIsAHintNotABlock(): void
    {
        $daten = self::eingabe(['iban' => 'DE00123']);

        self::assertSame('DE00123', $daten->wert('iban'));
        self::assertSame(['Die IBAN ist nicht gültig (Prüfziffer).'], $daten->hinweise());
        self::assertSame([], self::eingabe(['iban' => 'DE89370400440532013000'])->hinweise());
    }

    public function testDirectDebitNeedsIbanAndCreditorId(): void
    {
        self::assertFalse(Vereinsdaten::leer()->lastschriftMoeglich());
        self::assertFalse(self::eingabe(['iban' => 'DE89370400440532013000'])->lastschriftMoeglich());
        self::assertTrue(self::eingabe(['iban' => 'DE89370400440532013000', 'glaeubiger_id' => 'DE98ZZZ09999999999'])->lastschriftMoeglich());
    }

    public function testTheShortNameWinsForDisplay(): void
    {
        self::assertSame('SV Beispiel', self::eingabe(['vereinsname' => 'Sportverein Beispielstadt e. V.', 'kurzname' => 'SV Beispiel'])->anzeigename());
        self::assertSame('Sportverein Beispielstadt e. V.', self::eingabe(['vereinsname' => 'Sportverein Beispielstadt e. V.'])->anzeigename());
    }

    public function testStoredDataRoundTripsAndIgnoresUnknownKeys(): void
    {
        $daten = self::eingabe(['vereinsname' => 'SV', 'ort' => 'Beispielstadt']);

        $zurueck = Vereinsdaten::ausGespeichertem([...$daten->alsListe(), 'fremd' => 'x']);

        self::assertSame($daten->alsListe(), $zurueck->alsListe());
        self::assertSame(array_keys(Vereinsdaten::FELDER), array_keys($zurueck->alsListe()));
    }
}
