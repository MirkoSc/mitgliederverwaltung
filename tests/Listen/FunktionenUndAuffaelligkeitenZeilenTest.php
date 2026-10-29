<?php

declare(strict_types=1);

namespace App\Tests\Listen;

use App\Domain\FunktionsZuordnung;
use App\Listen\AuffaelligkeitenListe;
use App\Listen\FunktionenListe;
use App\Domain\Mitglied;
use App\Service\Mitglied\Hinweis;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\MitgliedHinweise;
use PHPUnit\Framework\TestCase;

/**
 * The row mapping of the lists "Funktionen" and "Auffälligkeiten" as pure
 * functions (docs/spec/09-listen-und-exporte.md section 2). Made-up data only.
 */
final class FunktionenUndAuffaelligkeitenZeilenTest extends TestCase
{
    public function testFunktionenSpaltenUndZeilenMitKontaktUndLeerfeldern(): void
    {
        $zuordnungen = [
            new FunktionsZuordnung(7, 'Muster', 'Max', 1, 'Übungsleiter', 'LIZ-1', 'Fußball, Kegeln', '0301', '', 'max@example.test'),
            new FunktionsZuordnung(8, 'Lehmann', '', 2, 'Bahndienst', '', '', '', '', ''),
        ];

        $spalten = array_map(static fn($s): string => $s->schluessel, FunktionenListe::spalten());
        self::assertSame(['name', 'funktion', 'lizenznummer', 'abteilungen', 'kontakt'], $spalten);

        $zeilen = FunktionenListe::zeilen($zuordnungen);
        self::assertCount(2, $zeilen);
        self::assertSame(7, $zeilen[0]->mitgliedId);
        self::assertSame('Muster, Max', $zeilen[0]->werte['name']);
        self::assertSame('0301, max@example.test', $zeilen[0]->werte['kontakt']);
        self::assertSame('Lehmann', $zeilen[1]->werte['name'], 'An empty first name is left out, no stray comma.');
        self::assertSame('', $zeilen[1]->werte['kontakt']);
    }

    public function testAuffaelligkeitenEineZeileJeHinweisUndFilterNachArt(): void
    {
        $mitglied = new Mitglied(mitgliedsnummer: 1, nachname: 'Muster', vorname: 'Max', id: 7);
        $zweiter = new Mitglied(mitgliedsnummer: 2, nachname: 'Lehmann', vorname: 'Lena', id: 8);
        $eintraege = [
            new MitgliedHinweise($mitglied, [
                new Hinweis(HinweisArt::EintrittsdatumUnbekannt, 'Das Eintrittsdatum ist unbekannt.'),
                new Hinweis(HinweisArt::BlsvAngabenFehlen, 'Für die BLSV-Meldung fehlt das Geschlecht.'),
            ]),
            new MitgliedHinweise($zweiter, [
                new Hinweis(HinweisArt::BlsvAngabenFehlen, 'Für die BLSV-Meldung fehlt das Geburtsdatum.'),
            ]),
        ];

        $alle = AuffaelligkeitenListe::zeilen($eintraege, 'alle');
        self::assertCount(3, $alle, 'One row per hint, not per member.');
        self::assertSame(7, $alle[0]->mitgliedId);
        self::assertSame('Muster, Max', $alle[0]->werte['name']);
        self::assertSame('Eintrittsdatum unbekannt', $alle[0]->werte['art']);

        $blsv = AuffaelligkeitenListe::zeilen($eintraege, HinweisArt::BlsvAngabenFehlen->value);
        self::assertCount(2, $blsv);
        self::assertSame(['Muster, Max', 'Lehmann, Lena'], array_map(static fn($z): string => $z->werte['name'], $blsv));

        self::assertSame([], AuffaelligkeitenListe::zeilen($eintraege, HinweisArt::KindIst18->value));
    }
}
