<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Domain\Rolle;
use App\Listen\Liste;
use App\Listen\ListenErgebnis;
use App\Listen\ListenFilter;
use App\Listen\ListenNavigation;
use App\Listen\ListenZeile;
use App\Listen\Spalte;

/**
 * An example list over made-up people, for the tests of the list page and
 * the export. Not final: ListenSeiteTest derives a class with its own key
 * into app/src/Listen/, because the registry finds lists by file name.
 *
 * 120 rows ("Person 001" .. "Person 120"): enough for three pages. Every
 * seventh person has a semicolon in the name, every fifth a birthday on
 * 29 February, and only the admin sees the column "Beitrag".
 */
class BeispielListe implements Liste
{
    public const int ANZAHL = 120;

    public static function erzeugen(Dienste $dienste): static
    {
        return new static();
    }

    public function schluessel(): string
    {
        return 'beispiel';
    }

    public function titel(): string
    {
        return 'Beispielliste';
    }

    public function beschreibung(): string
    {
        return 'Erfundene Personen für die Tests.';
    }

    public function reihenfolge(): int
    {
        return 50;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function filter(): array
    {
        return [
            ListenFilter::auswahl('gruppe', 'Gruppe', ['alle' => 'Alle Gruppen', 'a' => 'Gruppe A', 'b' => 'Gruppe B']),
            ListenFilter::haken('nur_gerade', 'Nur gerade Nummern'),
            ListenFilter::jahr('jahr', 'Jahr', 2027),
        ];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $spalten = [
            Spalte::text('name', 'Name'),
            Spalte::datum('geburtstag', 'Geburtstag'),
            Spalte::zahl('alter', 'Alter'),
            Spalte::text('gruppe', 'Gruppe', nebenspalte: true),
        ];
        if ($konto->rolle === Rolle::Admin) {
            $spalten[] = Spalte::geld('beitrag', 'Beitrag');
        }

        $zeilen = [];
        for ($n = 1; $n <= self::ANZAHL; $n++) {
            $gruppe = $n % 2 === 0 ? 'a' : 'b';
            if ($filter['gruppe'] !== 'alle' && $filter['gruppe'] !== $gruppe) {
                continue;
            }
            if ($filter['nur_gerade'] === true && $n % 2 !== 0) {
                continue;
            }
            $werte = [
                'name' => sprintf($n % 7 === 0 ? 'Person %03d; Z' : 'Person %03d', $n),
                'geburtstag' => $n % 5 === 0 ? '2000-02-29' : sprintf('2000-%02d-%02d', 1 + $n % 12, 1 + $n % 28),
                'alter' => (int) $filter['jahr'] - 2000,
                'gruppe' => strtoupper($gruppe),
                'beitrag' => $n * 150 + 5,
            ];
            $zeilen[] = new ListenZeile($werte, $n, $n % 10 === 0 ? 'rund' : null);
        }

        return new ListenErgebnis(
            $spalten,
            $zeilen,
            ['Zwei Personen haben kein Geburtsdatum.'],
            new ListenNavigation('Jahr ' . $filter['jahr'], ['jahr' => (int) $filter['jahr'] - 1], ['jahr' => (int) $filter['jahr'] + 1]),
        );
    }

    public function dateiname(array $filter): string
    {
        return 'Beispiel ' . $filter['jahr'] . '/' . $filter['gruppe'];
    }
}
