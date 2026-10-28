<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\FamilienRolle;
use App\Domain\Geschlecht;
use App\Domain\Herkunft;
use App\Domain\Mitglied;
use App\Domain\Zahlart;
use PHPUnit\Framework\TestCase;

/** The member as a value (docs/spec/07-mitglieder.md sections 1 and 7); made-up data. */
final class MitgliedTest extends TestCase
{
    private static function voll(): Mitglied
    {
        return new Mitglied(
            mitgliedsnummer: 4711,
            nachname: 'Mustermann',
            vorname: 'Erika',
            anrede: 'Frau',
            titel: 'Dr.',
            namenszusatz: 'von',
            geschlecht: Geschlecht::W,
            geburtsdatum: new \DateTimeImmutable('1980-02-29'),
            strasse: 'Beispielweg 1',
            plz: '12345',
            ort: 'Beispielstadt',
            land: 'D',
            telefon: '01234 5678',
            handy: '0170 1234567',
            email: 'erika@example.org',
            eintrittsdatum: new \DateTimeImmutable('2001-05-01'),
            austrittsdatum: new \DateTimeImmutable('2027-12-31'),
            austrittsgrund: 'Austritt',
            aktiv: false,
            abteilungIds: [3, 1],
            beitragsartId: 2,
            zahlart: Zahlart::Selbst,
            iban: 'DE02120300000000202051',
            bic: 'BYLADEM1001',
            kontoinhaber: 'Max Mustermann',
            mandatsreferenz: 'M-4711',
            mandatsdatum: new \DateTimeImmutable('2001-05-02'),
            familieId: 9,
            familieRolle: FamilienRolle::Zahler,
            funktionIds: [2],
            lizenznummer: 'UL-1',
            bemerkung: "Zeile 1\nZeile 2",
            herkunft: Herkunft::Import,
            id: 5,
            version: 3,
        );
    }

    public function testDataRoundTripsThroughAVersion(): void
    {
        $mitglied = self::voll();
        $json = json_decode(json_encode($mitglied->daten(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $zurueck = Mitglied::ausDaten($json, $mitglied);

        self::assertSame($mitglied->daten(), $zurueck->daten());
        self::assertSame(5, $zurueck->id);
        self::assertSame(3, $zurueck->version);
        self::assertSame(Herkunft::Import, $zurueck->herkunft);
    }

    public function testDataIsStableRegardlessOfIdOrder(): void
    {
        $a = new Mitglied(1, 'A', abteilungIds: [3, 1, 2], funktionIds: [2, 1]);
        $b = new Mitglied(1, 'A', abteilungIds: [1, 2, 3, 3], funktionIds: [1, 2]);

        self::assertSame($a->daten(), $b->daten());
        self::assertSame([1, 2, 3], $a->daten()['abteilungen']);
    }

    /** Row fields (id, version, timestamps, origin) are not member data. */
    public function testRowFieldsAreNotPartOfTheData(): void
    {
        $a = new Mitglied(1, 'A');
        $b = new Mitglied(1, 'A', herkunft: Herkunft::Import, id: 7, version: 4, createdAt: new \DateTimeImmutable());

        self::assertSame($a->daten(), $b->daten());
        self::assertArrayNotHasKey('herkunft', $a->daten());
        self::assertArrayNotHasKey('id', $a->daten());
    }

    public function testAnOlderOrBrokenVersionFormatStillReads(): void
    {
        $aktuell = new Mitglied(12, 'Neu', id: 1, version: 2);

        $alt = Mitglied::ausDaten(['nachname' => 'Alt', 'geschlecht' => 'x', 'geburtsdatum' => '2001-02-30', 'abteilungen' => [1, 'zwei']], $aktuell);

        self::assertSame('Alt', $alt->nachname);
        self::assertSame(12, $alt->mitgliedsnummer);
        self::assertNull($alt->geschlecht);
        self::assertNull($alt->geburtsdatum);
        self::assertSame([1], $alt->abteilungIds);
        self::assertSame('D', $alt->land);
        self::assertTrue($alt->aktiv);
        self::assertSame(Zahlart::Lastschrift, $alt->zahlart);
    }

    public function testFormerMemberIsComputedFromTheDateOfLeaving(): void
    {
        $mitglied = new Mitglied(1, 'A', austrittsdatum: new \DateTimeImmutable('2026-06-30'));

        self::assertFalse($mitglied->istEhemalig(new \DateTimeImmutable('2026-06-29 23:59')));
        self::assertTrue($mitglied->istEhemalig(new \DateTimeImmutable('2026-06-30 00:00')));
        self::assertTrue($mitglied->istEhemalig(new \DateTimeImmutable('2027-01-01')));
        self::assertFalse(new Mitglied(1, 'A')->istEhemalig(new \DateTimeImmutable()));
    }

    public function testAge(): void
    {
        self::assertSame(46, self::voll()->alterAm(new \DateTimeImmutable('2026-02-28')));
        self::assertNull(new Mitglied(1, 'A')->alterAm(new \DateTimeImmutable()));
    }

    public function testMandateDefaults(): void
    {
        $vereinsDefault = new \DateTimeImmutable('2010-01-01');
        $ohne = new Mitglied(815, 'A');
        $mitEintritt = new Mitglied(815, 'A', eintrittsdatum: new \DateTimeImmutable('2015-03-01'));

        self::assertSame('815', $ohne->effektiveMandatsreferenz());
        self::assertSame('M-4711', self::voll()->effektiveMandatsreferenz());
        self::assertSame($vereinsDefault, $ohne->effektivesMandatsdatum($vereinsDefault));
        self::assertSame('2015-03-01', $mitEintritt->effektivesMandatsdatum($vereinsDefault)?->format('Y-m-d'));
        self::assertSame('2001-05-02', self::voll()->effektivesMandatsdatum($vereinsDefault)?->format('Y-m-d'));
    }

    public function testNames(): void
    {
        self::assertSame('Dr. Erika Mustermann von', self::voll()->anzeigeName());
        self::assertSame('Max Mustermann', self::voll()->kontoinhaberName());
        self::assertSame('Erika Muster', new Mitglied(1, 'Muster', vorname: 'Erika')->kontoinhaberName());
    }

    public function testIbanCheck(): void
    {
        self::assertTrue(self::voll()->hatGueltigeIban());
        self::assertFalse(new Mitglied(1, 'A', iban: 'DE02120300000000202052')->hatGueltigeIban());
        self::assertFalse(new Mitglied(1, 'A')->hatGueltigeIban());
    }
}
