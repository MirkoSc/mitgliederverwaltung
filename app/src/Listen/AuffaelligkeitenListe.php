<?php

declare(strict_types=1);

namespace App\Listen;

use App\Dienste;
use App\Domain\Konto;
use App\Domain\Mitglied;
use App\Domain\Permission;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\HinweisLader;
use App\Service\Mitglied\MitgliedHinweise;

/**
 * "Auffälligkeiten" (docs/spec/09-listen-und-exporte.md section 2): every
 * hint of every current member (07 section 6), one row per hint. Filter: the
 * kind of hint. Hints are computed, never stored, so the filter runs on the
 * computed rows, not in SQL.
 *
 * Plus the returned direct debits (#133) as a kind of its own - fee data,
 * so only for an account with beitraege.sehen. They are no member hint: the
 * member page shows them next to the remark, not on top.
 */
final readonly class AuffaelligkeitenListe implements Liste
{
    private const string ALLE = 'alle';

    public const string RUECKLASTSCHRIFT = 'ruecklastschrift';

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
        $optionen[self::RUECKLASTSCHRIFT] = 'Rücklastschrift';

        return [ListenFilter::auswahl('art', 'Art des Hinweises', $optionen)];
    }

    public function ergebnis(array $filter, Konto $konto): ListenErgebnis
    {
        $heute = new \DateTimeImmutable('today');
        $pdo = $this->dienste->pdo();
        $art = (string) $filter['art'];
        $zeilen = $art === self::RUECKLASTSCHRIFT ? [] : self::zeilen(HinweisLader::aus($pdo)->fuerAlle($heute), $art);
        $jahre = $konto->darf(Permission::BeitraegeSehen) && ($art === self::ALLE || $art === self::RUECKLASTSCHRIFT)
            ? new JahresbeitragRepository($pdo)->ruecklastschriftJahre()
            : [];
        if ($jahre !== []) {
            $mitglieder = new MitgliedRepository($pdo)->alle(false, $heute);
            $zeilen = self::inMitgliederReihenfolge([...$zeilen, ...self::ruecklastschriftZeilen($mitglieder, $jahre)], $mitglieder);
        }

        return new ListenErgebnis(self::spalten(), $zeilen);
    }

    /**
     * One row per current member with a returned direct debit: "in 2025, 2027".
     *
     * @param list<Mitglied> $mitglieder current members
     * @param array<int, list<int>> $jahre years by member id
     * @return list<ListenZeile>
     */
    public static function ruecklastschriftZeilen(array $mitglieder, array $jahre): array
    {
        $zeilen = [];
        foreach ($mitglieder as $m) {
            $seine = $m->id === null ? [] : ($jahre[$m->id] ?? []);
            if ($seine !== []) {
                $zeilen[] = new ListenZeile(
                    ['name' => self::name($m), 'art' => 'Rücklastschrift', 'hinweis' => 'Rücklastschrift in ' . implode(', ', $seine)],
                    $m->id,
                    reiter: 'daten',
                );
            }
        }

        return $zeilen;
    }

    /**
     * The rows in the order the members come from the database (the order
     * the hints already have); stable, so a member's rows keep theirs.
     *
     * @param list<ListenZeile> $zeilen
     * @param list<Mitglied> $mitglieder
     * @return list<ListenZeile>
     */
    private static function inMitgliederReihenfolge(array $zeilen, array $mitglieder): array
    {
        $platz = array_flip(array_map(static fn(Mitglied $m): int => (int) $m->id, $mitglieder));
        usort($zeilen, static fn(ListenZeile $a, ListenZeile $b): int => ($platz[$a->mitgliedId] ?? PHP_INT_MAX) <=> ($platz[$b->mitgliedId] ?? PHP_INT_MAX));

        return $zeilen;
    }

    private static function name(Mitglied $m): string
    {
        return implode(', ', array_filter([$m->nachname, $m->vorname], static fn(string $t): bool => $t !== ''));
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
            $name = self::name($m);
            foreach ($eintrag->hinweise as $hinweis) {
                if ($art !== self::ALLE && $hinweis->art->value !== $art) {
                    continue;
                }
                $zeilen[] = new ListenZeile(
                    ['name' => $name, 'art' => $hinweis->art->bezeichnung(), 'hinweis' => $hinweis->text],
                    $m->id,
                    // The map's hints open the member where they are fixed (issue #108).
                    reiter: $hinweis->art->reiter(),
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
