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

    /**
     * Migration 300 rewrote every old licence-plate code (A -> AT, UK -> GB
     * ...); the hash must still be the one the first release built from the
     * old code, or the rows of geo_anschrift - points by hand too - are lost.
     */
    public function testEveryCountryRewrittenByMigration300KeepsItsGeocodeHash(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/300_land_iso.sql');
        preg_match_all("/SET land = '([A-Z]{2})' WHERE land (?:= '([A-Z]+)'|IN \\('([A-Z]+)')/", $sql, $treffer, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(18, count($treffer));

        foreach ($treffer as $t) {
            $iso = $t[1];
            $alt = $t[2] !== '' ? $t[2] : $t[3];
            $vorher = hash('sha256', 'weg 1|1010|wien|' . $alt);
            self::assertSame($vorher, new Anschrift('Weg 1', '1010', 'Wien', $iso)->hash(), "$alt -> $iso");
            self::assertSame($vorher, new Anschrift('Weg 1', '1010', 'Wien', $alt)->hash(), $alt);
            self::assertSame($alt, Laender::kennzeichenImHash($iso));
        }
    }

    public function testValuesMigration300LeftAloneKeepTheirHash(): void
    {
        foreach (['CH', 'NL', 'DEU', 'CDN'] as $wert) {
            self::assertSame(hash('sha256', 'weg 1|1010|wien|' . $wert), new Anschrift('Weg 1', '1010', 'Wien', strtolower($wert))->hash(), $wert);
        }
        self::assertSame('DE', new Anschrift('Weg 1', '1010', 'Wien', 'DEU')->land, 'the page and the geocoder get the ISO code');
        self::assertSame('Österreich', Laender::name('AUT'));
    }
}
