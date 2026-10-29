<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Permission;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\HinweisLader;
use App\Service\Mitglied\MitgliedHinweise;

/**
 * "Auffälligkeiten" (docs/spec/09-listen-und-exporte.md section 2): every
 * hint of every current member (07 section 6), one row per hint. Filter: the
 * kind of hint. Hints are computed, never stored, so the filter runs on the
 * computed rows, not in SQL.
 */
final readonly class AuffaelligkeitenListe implements Liste
{
    private const string ALLE = 'alle';

    /** Cheap to build: the database is asked only when the list is shown. */
    public function __construct(private Dienste $dienste)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function schluessel(): string
    {
        return 'auffaelligkeiten';
    }

    public function titel(): string
    {
        return 'Auffälligkeiten';
    }

    public function reihenfolge(): int
    {
        return 50;
    }

    public function recht(): Permission
    {
        return Permission::ListenSehen;
    }

    public function beschreibung(): string
    {
        return 'Alle Hinweise zu allen aktuellen Mitgliedern. Hinweise warnen nur, sie sind keine Fehler.';
    }

    public function filter(): array
    {
        $optionen = [self::ALLE => 'Alle Hinweise'];
        foreach (HinweisArt::cases() as $art) {
            $optionen[$art->value] = $art->bezeichnung();
        }

        return [ListenFilter::auswahl('art', 'Art des Hinweises', $optionen)];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $eintraege = HinweisLader::aus($this->dienste->pdo())->fuerAlle(new \DateTimeImmutable('today'));

        return new ListenErgebnis(self::spalten(), self::zeilen($eintraege, (string) $filter['art']));
    }

    /** @return list<Spalte> */
    public static function spalten(): array
    {
        return [
            Spalte::text('name', 'Name'),
            Spalte::text('art', 'Art', nebenspalte: true),
            Spalte::text('hinweis', 'Hinweis'),
        ];
    }

    /**
     * One row per hint of the kind $art ("alle" for every kind).
     *
     * @param list<MitgliedHinweise> $eintraege
     * @return list<ListenZeile>
     */
    public static function zeilen(array $eintraege, string $art): array
    {
        $zeilen = [];
        foreach ($eintraege as $eintrag) {
            $m = $eintrag->mitglied;
            $name = implode(', ', array_filter([$m->nachname, $m->vorname], static fn(string $t): bool => $t !== ''));
            foreach ($eintrag->hinweise as $hinweis) {
                if ($art !== self::ALLE && $hinweis->art->value !== $art) {
                    continue;
                }
                $zeilen[] = new ListenZeile(
                    ['name' => $name, 'art' => $hinweis->art->bezeichnung(), 'hinweis' => $hinweis->text],
                    $m->id,
                );
            }
        }

        return $zeilen;
    }

    public function dateiname(array $filter): string
    {
        return $filter['art'] === self::ALLE ? 'auffaelligkeiten-alle' : 'auffaelligkeiten-' . $filter['art'];
    }
}
