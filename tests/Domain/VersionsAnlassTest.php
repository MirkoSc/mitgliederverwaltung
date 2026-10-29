<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Vermerk;
use App\Domain\VersionsAnlass;
use PHPUnit\Framework\TestCase;

final class VersionsAnlassTest extends TestCase
{
    public function testTheTextsOfTheSpec(): void
    {
        self::assertSame('angelegt', VersionsAnlass::angelegt()->text);
        self::assertSame('geaendert', VersionsAnlass::geaendert()->text);
        self::assertSame('importiert', VersionsAnlass::importiert()->text);
        self::assertSame('abgleich', VersionsAnlass::abgleich()->text);
        self::assertSame('wiederhergestellt aus Version 3', VersionsAnlass::wiederhergestellt(3)->text);
    }

    public function testARestoreKnowsWhereItCameFrom(): void
    {
        self::assertSame(12, VersionsAnlass::ausText('wiederhergestellt aus Version 12')->wiederhergestelltAus());
        self::assertNull(VersionsAnlass::geaendert()->wiederhergestelltAus());
        self::assertNull(VersionsAnlass::ausText('wiederhergestellt aus Version x')->wiederhergestelltAus());
        self::assertSame('Wiederhergestellt aus Version 12', VersionsAnlass::wiederhergestellt(12)->bezeichnung());
        self::assertSame('Geändert', VersionsAnlass::geaendert()->bezeichnung());
    }

    public function testVermerkRoundTripAndAmountOnlyChangeableWhileOpen(): void
    {
        $vermerk = new Vermerk(3, new \DateTimeImmutable('2026-03-01 10:15:00'), 'Betrag angepasst');
        self::assertEquals($vermerk, Vermerk::ausArray($vermerk->alsArray()));

        $offen = new Jahresbeitrag(1, 2026, 8500);
        self::assertTrue($offen->betragAenderbar());
        self::assertFalse($offen->mit(['status' => JahresbeitragStatus::ImEinzug])->betragAenderbar());
        self::assertFalse($offen->mit(['status' => JahresbeitragStatus::Bezahlt])->betragAenderbar());
        self::assertCount(1, $offen->mitVermerk($vermerk)->vermerke);
        self::assertSame([], $offen->vermerke);
    }
}
