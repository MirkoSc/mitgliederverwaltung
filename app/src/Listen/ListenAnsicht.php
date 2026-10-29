<?php

declare(strict_types=1);

namespace App\Listen;

use App\Domain\Konto;

/**
 * A list for one request: validated filter values and the sort order on top
 * of what the list delivers. The page and the export both build it from the
 * same parameters - so the export has exactly the columns, filters and order
 * of the view (09 section 1).
 */
final readonly class ListenAnsicht
{
    public const int PRO_SEITE = 50;

    /**
     * @param array<string, string|int|bool> $filter
     * @param list<ListenZeile> $zeilen all rows, sorted
     */
    private function __construct(
        public Liste $liste,
        public array $filter,
        public ListenErgebnis $ergebnis,
        public ?string $sortierung,
        public bool $absteigend,
        public array $zeilen,
    ) {
    }

    /**
     * @param array<string, mixed> $eingabe query string of the page or posted form of the export
     */
    public static function aus(Liste $liste, array $eingabe, Konto $konto): self
    {
        $filter = [];
        foreach ($liste->filter() as $f) {
            $filter[$f->name] = $f->lesen($eingabe);
        }
        $ergebnis = $liste->ergebnis($filter, $konto);

        $sortierung = is_string($eingabe['sortierung'] ?? null) ? $eingabe['sortierung'] : null;
        $spalte = null;
        foreach ($ergebnis->spalten as $s) {
            if ($s->schluessel === $sortierung) {
                $spalte = $s;
            }
        }
        $absteigend = $spalte !== null && ($eingabe['richtung'] ?? null) === 'ab';

        return new self(
            $liste,
            $filter,
            $ergebnis,
            $spalte?->schluessel,
            $absteigend,
            $spalte === null ? $ergebnis->zeilen : self::sortiert($ergebnis->zeilen, $spalte, $absteigend),
        );
    }

    public function seitenzahl(): int
    {
        return max(1, intdiv(count($this->zeilen) + self::PRO_SEITE - 1, self::PRO_SEITE));
    }

    /** The page to show: 1 to seitenzahl(), anything else is clamped. */
    public function seite(mixed $gewuenscht): int
    {
        $nummer = is_string($gewuenscht) && preg_match('/^\d+$/D', $gewuenscht) === 1 ? (int) $gewuenscht : 1;

        return min(max(1, $nummer), $this->seitenzahl());
    }

    /** @return list<ListenZeile> */
    public function zeilenAufSeite(int $seite): array
    {
        return array_slice($this->zeilen, ($seite - 1) * self::PRO_SEITE, self::PRO_SEITE);
    }

    /**
     * @param list<ListenZeile> $zeilen
     * @return list<ListenZeile>
     */
    private static function sortiert(array $zeilen, Spalte $spalte, bool $absteigend): array
    {
        $schluessel = static fn(ListenZeile $z): string|int|null => self::sortwert($z->werte[$spalte->schluessel] ?? null, $spalte->typ);
        // usort is stable: rows with equal values keep the list's own order.
        usort($zeilen, static function (ListenZeile $a, ListenZeile $b) use ($schluessel, $absteigend): int {
            $x = $schluessel($a);
            $y = $schluessel($b);
            // Empty cells last, whichever way it is sorted.
            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null);
            }

            $vergleich = is_string($x) && is_string($y) ? strcmp($x, $y) : $x <=> $y; // "10" is no number here

            return $absteigend ? -$vergleich : $vergleich;
        });

        return $zeilen;
    }

    private static function sortwert(string|int|\DateTimeInterface|null $wert, SpaltenTyp $typ): string|int|null
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d H:i:s');
        }
        if ($typ === SpaltenTyp::Zahl || $typ === SpaltenTyp::Geld) {
            return (int) $wert;
        }
        if ($typ === SpaltenTyp::Datum) {
            return (string) $wert; // "Y-m-d" sorts as text
        }

        // An umlaut sorts as its base letter ("Müller" as "Muller", after "Mueller").
        return strtr(mb_strtolower((string) $wert), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
    }
}
