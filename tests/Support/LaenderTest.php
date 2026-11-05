<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Karte\Anschrift;
use App\Support\Laender;
use PHPUnit\Framework\TestCase;

final class LaenderTest extends TestCase
{
    public function testTheSelectionStartsWithGermanyAndItsNeighbours(): void
    {
        $codes = array_keys(Laender::auswahl());

        self::assertSame(['DE', 'AT', 'CH', 'FR', 'NL', 'BE', 'LU', 'DK', 'PL', 'CZ'], array_slice($codes, 0, 10));
        self::assertGreaterThan(240, count($codes));
        self::assertSame('Deutschland', Laender::auswahl()['DE']);
    }

    public function testNameIsoCodeAndLicencePlateCodeAreUnderstood(): void
    {
        self::assertSame('DE', Laender::code('Deutschland'));
        self::assertSame('DE', Laender::code(' deutschland '));
        self::assertSame('DE', Laender::code('D'));
        self::assertSame('DE', Laender::code('de'));
        self::assertSame('AT', Laender::code('A'));
        self::assertSame('AT', Laender::code('Österreich'));
        self::assertSame('CH', Laender::code('CH'));
        self::assertSame('IT', Laender::code('I'));
        self::assertNull(Laender::code('Atlantis'));
        self::assertNull(Laender::code(''));
    }

    public function testNameOfOldAndUnknownValues(): void
    {
        self::assertSame('Deutschland', Laender::name('D'));
        self::assertSame('Österreich', Laender::name('AT'));
        self::assertSame('Atlantis', Laender::name('Atlantis'));
        self::assertSame('', Laender::name(''));
        self::assertSame('DE', Laender::iso('D'));
    }

    public function testGermanyKeepsItsGeocodeHashFromBeforeTheSwitch(): void
    {
        $vorher = hash('sha256', 'hauptstraße 1|12345|musterstadt|D');

        self::assertSame($vorher, new Anschrift('Hauptstr. 1', '12345', 'Musterstadt', 'DE')->hash());
        self::assertSame($vorher, new Anschrift('Hauptstr. 1', '12345', 'Musterstadt', 'D')->hash());
        self::assertSame($vorher, new Anschrift('Hauptstr. 1', '12345', 'Musterstadt')->hash());
    }
}
