<?php

declare(strict_types=1);

namespace App\Tests\Listen;

use App\Domain\Permission;
use App\Domain\Rolle;
use App\Domain\Zahlart;
use App\Listen\SelbstKassierenListe;
use PHPUnit\Framework\TestCase;

/**
 * "Selbst kassieren" as pure functions (docs/spec/09-listen-und-exporte.md
 * section 2). Made-up data only.
 */
final class SelbstKassierenTest extends TestCase
{
    private const string GUELTIGE_IBAN = 'DE89370400440532013000';

    public function testSelbstzahlerUndOhneGueltigeIbanWerdenKassiert(): void
    {
        self::assertTrue(SelbstKassierenListe::istSelbstKassieren(Zahlart::Selbst, self::GUELTIGE_IBAN));
        self::assertTrue(SelbstKassierenListe::istSelbstKassieren(Zahlart::Lastschrift, ''));
        self::assertTrue(SelbstKassierenListe::istSelbstKassieren(Zahlart::Lastschrift, 'DE00000000000000000000'));
        self::assertFalse(SelbstKassierenListe::istSelbstKassieren(Zahlart::Lastschrift, self::GUELTIGE_IBAN));
    }

    public function testZeilenNurFuerKassiertePositionenMitSpaltenUndKontakt(): void
    {
        $zeilen = SelbstKassierenListe::zeilen([
            ['mitglied_id' => 1, 'jahr' => 2027, 'betrag_cent' => 8500, 'nachname' => 'Muster', 'vorname' => 'Max',
                'telefon' => '0301', 'handy' => '', 'email' => 'max@example.test', 'zahlart' => 'selbst', 'iban' => self::GUELTIGE_IBAN],
            ['mitglied_id' => 2, 'jahr' => 2027, 'betrag_cent' => 8500, 'nachname' => 'Lehmann', 'vorname' => '',
                'telefon' => '', 'handy' => '', 'email' => '', 'zahlart' => 'lastschrift', 'iban' => ''],
            ['mitglied_id' => 3, 'jahr' => 2027, 'betrag_cent' => 8500, 'nachname' => 'Sicher', 'vorname' => 'Sina',
                'telefon' => '', 'handy' => '', 'email' => '', 'zahlart' => 'lastschrift', 'iban' => self::GUELTIGE_IBAN],
        ]);

        self::assertCount(2, $zeilen, 'The member with a valid IBAN and direct debit is left out.');
        self::assertSame(1, $zeilen[0]->mitgliedId);
        self::assertSame('Muster, Max', $zeilen[0]->werte['name']);
        self::assertSame(8500, $zeilen[0]->werte['betrag']);
        self::assertSame('0301, max@example.test', $zeilen[0]->werte['kontakt']);
        self::assertSame('Lehmann', $zeilen[1]->werte['name'], 'No stray comma for a missing first name.');
    }

    public function testAlleRollenSehenDieListe(): void
    {
        $recht = (new \ReflectionClass(SelbstKassierenListe::class))->newInstanceWithoutConstructor()->recht();
        self::assertSame(Permission::BeitraegeSehen, $recht);
        self::assertTrue(Rolle::Admin->darf($recht));
        self::assertTrue(Rolle::Lesend->darf($recht));
        self::assertTrue(Rolle::Vorstand->darf($recht));
    }

    public function testDateinameOhnePersonendaten(): void
    {
        $liste = (new \ReflectionClass(SelbstKassierenListe::class))->newInstanceWithoutConstructor();
        self::assertSame('selbst-kassieren-2027', $liste->dateiname(['jahr' => 2027]));
    }
}
