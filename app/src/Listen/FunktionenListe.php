<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\FunktionsZuordnung;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Repository\FunktionRepository;

/**
 * "Funktionen" (docs/spec/09-listen-und-exporte.md section 2): one row per
 * member and club function, with the licence number. Filter: the function.
 */
final readonly class FunktionenListe implements Liste
{
    /** Cheap to build: the database is asked only when the list is shown. */
    public function __construct(private Dienste $dienste)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self($dienste);
    }

    private function funktionen(): FunktionRepository
    {
        return new FunktionRepository($this->dienste->pdo());
    }

    public function schluessel(): string
    {
        return 'funktionen';
    }

    public function titel(): string
    {
        return 'Funktionen';
    }

    public function reihenfolge(): int
    {
        return 30;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function beschreibung(): string
    {
        return 'Wer welche Funktion im Verein hat, mit Lizenznummer.';
    }

    public function filter(): array
    {
        $optionen = ['alle' => 'Alle Funktionen'];
        foreach ($this->funktionen()->alle() as $funktion) {
            $optionen[(string) $funktion->id] = $funktion->name . ($funktion->aktiv ? '' : ' (inaktiv)');
        }

        return [ListenFilter::auswahl('funktion', 'Funktion', $optionen)];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $funktionId = $filter['funktion'] === 'alle' ? null : (int) $filter['funktion'];
        $zuordnungen = $this->funktionen()->zuordnungen($funktionId, new \DateTimeImmutable('today'));

        return new ListenErgebnis(self::spalten(), self::zeilen($zuordnungen));
    }

    /** @return list<Spalte> */
    public static function spalten(): array
    {
        return [
            Spalte::text('name', 'Name'),
            Spalte::text('funktion', 'Funktion'),
            Spalte::text('lizenznummer', 'Lizenznummer'),
            Spalte::text('abteilungen', 'Abteilungen', nebenspalte: true),
            Spalte::text('kontakt', 'Kontakt', nebenspalte: true),
        ];
    }

    /**
     * @param list<FunktionsZuordnung> $zuordnungen
     * @return list<ListenZeile>
     */
    public static function zeilen(array $zuordnungen): array
    {
        return array_map(
            static fn(FunktionsZuordnung $z): ListenZeile => new ListenZeile(
                [
                    'name' => implode(', ', array_filter([$z->nachname, $z->vorname], static fn(string $t): bool => $t !== '')),
                    'funktion' => $z->funktion,
                    'lizenznummer' => $z->lizenznummer,
                    'abteilungen' => $z->abteilungen,
                    'kontakt' => implode(', ', array_filter(
                        [$z->telefon, $z->handy, $z->email],
                        static fn(string $t): bool => $t !== '',
                    )),
                ],
                $z->mitgliedId,
            ),
            $zuordnungen,
        );
    }

    public function dateiname(array $filter): string
    {
        return $filter['funktion'] === 'alle' ? 'funktionen-alle' : 'funktionen-' . $filter['funktion'];
    }
}
