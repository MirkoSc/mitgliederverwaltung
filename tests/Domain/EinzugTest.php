<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Einzug;
use App\Domain\EinzugPosten;
use App\Domain\EinzugStatus;
use PHPUnit\Framework\TestCase;

/**
 * The archived transactions of a direct debit (`einzug.daten`,
 * docs/spec/08-beitraege.md section 6) and its closed state.
 */
final class EinzugTest extends TestCase
{
    private static function einzug(EinzugStatus $status = EinzugStatus::Erzeugt, array $daten = []): Einzug
    {
        return new Einzug($status, new \DateTimeImmutable('2026-02-13'), 1, new \DateTimeImmutable('2026-02-10 10:15:00'), 1, 8500, daten: $daten);
    }

    public function testThePostenRoundTripThroughTheStoredArray(): void
    {
        $posten = new EinzugPosten(3, 4, 1001, 'Musterfrau, Erika', 2026, 8500, '1001', 'E7-1001-2026');

        $gelesen = self::einzug(daten: [$posten->alsArray()])->posten();

        self::assertEquals([$posten], $gelesen);
    }

    public function testBrokenEntriesReadAsEmptyValuesInsteadOfFailing(): void
    {
        $posten = self::einzug(daten: ['kaputt', ['name' => 'Musterfrau', 'betrag_cent' => '85']])->posten();

        self::assertCount(2, $posten);
        self::assertSame('', $posten[0]->name);
        self::assertSame('Musterfrau', $posten[1]->name);
        self::assertSame(0, $posten[1]->betragCent, 'a string is not an amount');
    }

    public function testOnlyACreatedRunIsOpen(): void
    {
        self::assertFalse(self::einzug()->abgeschlossen());
        self::assertTrue(self::einzug(EinzugStatus::Bestaetigt)->abgeschlossen());
        self::assertTrue(self::einzug(EinzugStatus::Verworfen)->abgeschlossen());
    }
}
