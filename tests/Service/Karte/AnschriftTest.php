<?php

declare(strict_types=1);

namespace App\Tests\Service\Karte;

use App\Service\Karte\Anschrift;
use PHPUnit\Framework\TestCase;

final class AnschriftTest extends TestCase
{
    public function testSpellingsOfOneAddressShareOneHash(): void
    {
        $a = new Anschrift('Hauptstr. 1', '12345', 'Musterstadt');
        $b = new Anschrift('  hauptstraße   1 ', '12345', 'MUSTERSTADT', 'd');
        $c = new Anschrift('Hauptstrasse 1', '12345', 'Musterstadt', '');

        self::assertSame($a->hash(), $b->hash());
        self::assertSame($a->hash(), $c->hash());
        self::assertSame(64, strlen($a->hash()));
    }

    public function testAnotherHouseOrTownIsAnotherHash(): void
    {
        $a = new Anschrift('Hauptstraße 1', '12345', 'Musterstadt');

        self::assertNotSame($a->hash(), new Anschrift('Hauptstraße 2', '12345', 'Musterstadt')->hash());
        self::assertNotSame($a->hash(), new Anschrift('Hauptstraße 1', '12346', 'Musterstadt')->hash());
        self::assertNotSame($a->hash(), new Anschrift('Hauptstraße 1', '12345', 'Musterstadt', 'A')->hash());
    }

    public function testWithoutPostcodeAndTownThereIsNothingToFind(): void
    {
        self::assertTrue(new Anschrift('Hauptstraße 1', '', '')->istLeer());
        self::assertFalse(new Anschrift('', '', 'Musterstadt')->istLeer());
        self::assertFalse(new Anschrift('', '12345', '')->istLeer());
    }

    public function testTheGeocoderGetsTheTownUpToItsFirstMinus(): void
    {
        self::assertSame('Musterort', new Anschrift('', '12345', 'Musterort - OAB')->ortFuerSuche());
        self::assertSame('Musterort', new Anschrift('', '12345', 'Musterort-Unterdorf')->ortFuerSuche());
        self::assertSame('Musterort', new Anschrift('', '12345', 'Musterort - Ober - Unterdorf')->ortFuerSuche());
        self::assertSame('Musterort', new Anschrift('', '12345', 'Musterort')->ortFuerSuche());
        self::assertSame('- OAB', new Anschrift('', '12345', '- OAB')->ortFuerSuche(), 'nothing before the minus: unchanged');
        self::assertSame('12345 Musterort - OAB', new Anschrift('', '12345', 'Musterort - OAB')->text(), 'the page shows the town as entered');
    }

    public function testTheTextNamesTheCountryOnlyAbroad(): void
    {
        self::assertSame('Hauptstraße 1, 12345 Musterstadt', new Anschrift('Hauptstraße 1', '12345', 'Musterstadt')->text());
        self::assertSame('Dorfweg 3, 6020 Innsbruck, A', new Anschrift('Dorfweg 3', '6020', 'Innsbruck', 'A')->text());
        self::assertSame('Musterstadt', new Anschrift('', '', 'Musterstadt')->text());
    }
}
