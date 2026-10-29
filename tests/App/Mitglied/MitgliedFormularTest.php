<?php

declare(strict_types=1);

namespace App\Tests\App\Mitglied;

use App\App\Mitglied\Auswahllisten;
use App\App\Mitglied\MitgliedFormular;
use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\FamilienRolle;
use App\Domain\Funktion;
use App\Domain\Geschlecht;
use App\Domain\Herkunft;
use App\Domain\Mitglied;
use App\Domain\Zahlart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading the member form (docs/spec/07-mitglieder.md section 1): only
 * number and surname are required, everything else is taken as entered.
 * Every name and number below is made up.
 */
final class MitgliedFormularTest extends TestCase
{
    private static function listen(): Auswahllisten
    {
        return new Auswahllisten(
            [new Abteilung('Fußball', '09', 1, true, 1), new Abteilung('Kegeln', '16', 2, false, 2)],
            [new Funktion('Übungsleiter', true, 1, true, 5), new Funktion('Bahndienst', false, 2, true, 6)],
            [new Beitragsart('Aktiv', 9600, id: 7), new Beitragsart('Alt', 1000, aktiv: false, id: 8)],
        );
    }

    /** @param array<string, mixed> $post */
    private static function lesen(array $post, ?Mitglied $basis = null): MitgliedFormular
    {
        return MitgliedFormular::gelesen($post, $basis ?? new Mitglied(1, ''), self::listen());
    }

    public function testNumberAndSurnameAreAllItNeeds(): void
    {
        $formular = self::lesen(['mitgliedsnummer' => '412', 'nachname' => 'Musterfrau']);

        self::assertSame([], $formular->fehler);
        self::assertNotNull($formular->mitglied);
        self::assertSame(412, $formular->mitglied->mitgliedsnummer);
        self::assertSame('Musterfrau', $formular->mitglied->nachname);
        self::assertSame('D', $formular->mitglied->land, 'the default country');
        self::assertTrue($formular->mitglied->aktiv);
        self::assertSame(Zahlart::Lastschrift, $formular->mitglied->zahlart);
        self::assertNull($formular->mitglied->eintrittsdatum, 'empty = unknown');
    }

    public function testMissingNumberAndSurnameAreErrors(): void
    {
        $formular = self::lesen(['mitgliedsnummer' => ' ', 'nachname' => '  ', 'vorname' => 'Erika']);

        self::assertNull($formular->mitglied);
        self::assertSame(['mitgliedsnummer', 'nachname'], array_keys($formular->fehler));
        self::assertSame('Erika', $formular->wert('vorname'), 'what was typed stays');
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function nummern(): iterable
    {
        yield 'plain' => ['412', 412];
        yield 'leading zeros' => ['0087', 87];
        yield 'largest' => ['4294967295', 4294967295];
        yield 'zero' => ['0', null];
        yield 'too large' => ['4294967296', null];
        yield 'letters' => ['41a', null];
        yield 'negative' => ['-5', null];
        yield 'decimal' => ['4.5', null];
    }

    #[DataProvider('nummern')]
    public function testTheMemberNumberIsAWholeNumberFromOne(string $eingabe, ?int $erwartet): void
    {
        $formular = self::lesen(['mitgliedsnummer' => $eingabe, 'nachname' => 'Muster']);

        self::assertSame($erwartet, $formular->mitglied?->mitgliedsnummer);
        self::assertSame($erwartet === null, $formular->fehler('mitgliedsnummer') !== null);
    }

    public function testDatesAreReadFromTheDateInputAndFromTypedGermanDates(): void
    {
        $formular = self::lesen([
            'mitgliedsnummer' => '1', 'nachname' => 'Muster',
            'geburtsdatum' => '1980-12-31', 'eintrittsdatum' => '1.3.2010', 'mandatsdatum' => '',
        ]);

        self::assertSame('1980-12-31', $formular->mitglied?->geburtsdatum?->format('Y-m-d'));
        self::assertSame('2010-03-01', $formular->mitglied?->eintrittsdatum?->format('Y-m-d'));
        self::assertNull($formular->mitglied?->mandatsdatum);
    }

    public function testAnUnreadableDateIsAnErrorInsteadOfBeingLost(): void
    {
        $formular = self::lesen(['mitgliedsnummer' => '1', 'nachname' => 'Muster', 'geburtsdatum' => '31.02.1980', 'austrittsdatum' => 'bald']);

        self::assertNull($formular->mitglied);
        self::assertSame(['geburtsdatum', 'austrittsdatum'], array_keys($formular->fehler));
        self::assertSame('bald', $formular->wert('austrittsdatum'));
    }

    public function testAllFieldsOfTheSpecAreRead(): void
    {
        $formular = self::lesen([
            'mitgliedsnummer' => '7', 'anrede' => 'Frau', 'titel' => 'Dr.', 'vorname' => 'Erika', 'nachname' => 'Musterfrau',
            'namenszusatz' => 'von', 'geschlecht' => 'w', 'geburtsdatum' => '1979-05-14', 'strasse' => 'Wiesenweg   3',
            'plz' => '12345', 'ort' => 'Musterstadt', 'land' => 'at', 'telefon' => '0123 4567', 'handy' => '0170 1234567',
            'email' => 'erika@example.org', 'eintrittsdatum' => '2001-01-01', 'austrittsdatum' => '2030-12-31',
            'austrittsgrund' => 'Austritt', 'aktiv' => '0', 'abteilungen' => ['1', '2'], 'beitragsart_id' => '7',
            'zahlart' => 'selbst', 'iban' => 'de89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'kontoinhaber' => 'Max Mustermann',
            'mandatsreferenz' => 'M-7', 'mandatsdatum' => '2001-02-01', 'funktionen' => ['5'], 'lizenznummer' => 'UL-1',
            'bemerkung' => "Zeile 1\nZeile 2",
        ]);

        $m = $formular->mitglied;
        self::assertNotNull($m);
        self::assertSame([
            'mitgliedsnummer' => 7, 'anrede' => 'Frau', 'titel' => 'Dr.', 'vorname' => 'Erika', 'nachname' => 'Musterfrau',
            'namenszusatz' => 'von', 'geschlecht' => 'w', 'geburtsdatum' => '1979-05-14', 'strasse' => 'Wiesenweg 3',
            'plz' => '12345', 'ort' => 'Musterstadt', 'land' => 'AT', 'telefon' => '0123 4567', 'handy' => '0170 1234567',
            'email' => 'erika@example.org', 'eintrittsdatum' => '2001-01-01', 'austrittsdatum' => '2030-12-31',
            'austrittsgrund' => 'Austritt', 'aktiv' => false, 'abteilungen' => [1, 2], 'beitragsart_id' => 7,
            'zahlart' => 'selbst', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'kontoinhaber' => 'Max Mustermann',
            'mandatsreferenz' => 'M-7', 'mandatsdatum' => '2001-02-01', 'familie_id' => null, 'familie_rolle' => null,
            'funktionen' => [5], 'lizenznummer' => 'UL-1', 'bemerkung' => "Zeile 1\nZeile 2",
        ], $m->daten());
    }

    public function testUnknownIdsAndValuesAreDropped(): void
    {
        $formular = self::lesen([
            'mitgliedsnummer' => '1', 'nachname' => 'Muster', 'abteilungen' => ['1', '99', 'x', ['2']],
            'funktionen' => '5', 'beitragsart_id' => '99', 'geschlecht' => 'x', 'zahlart' => 'bar',
        ]);

        self::assertSame([1], $formular->mitglied?->abteilungIds);
        self::assertSame([], $formular->mitglied?->funktionIds, 'a string where a list belongs');
        self::assertNull($formular->mitglied?->beitragsartId);
        self::assertNull($formular->mitglied?->geschlecht);
        self::assertSame(Zahlart::Lastschrift, $formular->mitglied?->zahlart);
    }

    public function testFamilyOriginAndRowFieldsComeFromTheStoredMember(): void
    {
        $gespeichert = new Mitglied(
            5, 'Alt', familieId: 3, familieRolle: FamilienRolle::Kind, herkunft: Herkunft::Import, id: 11, version: 4,
        );

        $m = self::lesen(['mitgliedsnummer' => '5', 'nachname' => 'Neu', 'familie_id' => '9', 'familie_rolle' => 'zahler'], $gespeichert)->mitglied;

        self::assertNotNull($m);
        self::assertSame('Neu', $m->nachname);
        self::assertSame(3, $m->familieId);
        self::assertSame(FamilienRolle::Kind, $m->familieRolle);
        self::assertSame(Herkunft::Import, $m->herkunft);
        self::assertSame(11, $m->id);
        self::assertSame(4, $m->version);
    }

    public function testTooLongTextIsCutToTheColumn(): void
    {
        $m = self::lesen(['mitgliedsnummer' => '1', 'nachname' => str_repeat('ä', 150), 'plz' => '123456789012', 'iban' => str_repeat('1', 50)])->mitglied;

        self::assertSame(100, mb_strlen($m?->nachname ?? ''));
        self::assertSame('1234567890', $m?->plz);
        self::assertSame(34, strlen($m?->iban ?? ''));
    }

    public function testTheFormShowsAStoredMemberAndReadsItBackUnchanged(): void
    {
        $gespeichert = new Mitglied(
            mitgliedsnummer: 87, nachname: 'Mustermann', vorname: 'Max', geschlecht: Geschlecht::M,
            geburtsdatum: new \DateTimeImmutable('2012-11-02'), aktiv: false, abteilungIds: [1], beitragsartId: 8,
            iban: 'DE89370400440532013000', funktionIds: [5, 6], bemerkung: "zwei\nZeilen", id: 3, version: 2,
        );

        $formular = MitgliedFormular::aus($gespeichert);
        self::assertSame('DE89 3704 0044 0532 0130 00', $formular->wert('iban'), 'grouped for reading');
        self::assertSame('0', $formular->wert('aktiv'));
        self::assertSame('2012-11-02', $formular->wert('geburtsdatum'));

        $post = [...$formular->werte, 'abteilungen' => array_map(strval(...), $formular->abteilungIds), 'funktionen' => array_map(strval(...), $formular->funktionIds)];
        self::assertSame($gespeichert->daten(), self::lesen($post, $gespeichert)->mitglied?->daten(), 'an unchanged form is no change');
    }

    public function testANewFormCarriesTheNextNumber(): void
    {
        $formular = MitgliedFormular::neu(1034);

        self::assertSame('1034', $formular->wert('mitgliedsnummer'));
        self::assertSame('D', $formular->wert('land'));
        self::assertSame('1', $formular->wert('aktiv'));
        self::assertSame('', $formular->wert('nachname'));
    }

    public function testAWrongIbanIsAHintNotAnError(): void
    {
        $formular = self::lesen(['mitgliedsnummer' => '1', 'nachname' => 'Muster', 'iban' => 'DE00 1234 5678 9012 3456 78']);

        self::assertSame([], $formular->fehler);
        self::assertNotNull($formular->mitglied);
        self::assertCount(1, MitgliedFormular::hinweise($formular->mitglied));
        self::assertSame([], MitgliedFormular::hinweise($formular->mitglied->mit(['iban' => 'DE89370400440532013000'])));
        self::assertSame([], MitgliedFormular::hinweise($formular->mitglied->mit(['iban' => ''])), 'a missing IBAN is another rule (07 section 6)');
    }

    public function testInactiveEntriesAreOfferedOnlyWhenTheMemberHasThem(): void
    {
        $listen = self::listen();

        self::assertSame(['Fußball'], array_map(static fn(Abteilung $a): string => $a->name, $listen->abteilungenFuer([])));
        self::assertSame(['Fußball', 'Kegeln'], array_map(static fn(Abteilung $a): string => $a->name, $listen->abteilungenFuer([2])));
        self::assertSame(['Aktiv'], array_map(static fn(Beitragsart $b): string => $b->name, $listen->beitragsartenFuer(null)));
        self::assertSame(['Aktiv', 'Alt'], array_map(static fn(Beitragsart $b): string => $b->name, $listen->beitragsartenFuer(8)));
        self::assertSame(['Übungsleiter'], $listen->funktionenMitLizenz());
    }
}
