<?php

declare(strict_types=1);

namespace App\Service\Statistik;

/**
 * One evaluation of the page "Statistik" (09 section 7): a table and the
 * chart drawn from it. Generic on purpose - one template
 * (app/views/statistik/auswertung.php) renders every one of them.
 */
final readonly class Auswertung
{
    /**
     * @param array<string, int> $reihen series name => colour slot 1-4 of
     *        public/css/teile/statistik.css (4 = grey, for "ohne Angabe")
     * @param list<AuswertungsZeile> $zeilen
     * @param AuswertungsZeile|null $zusammen the totals row; null where the
     *        rows overlap (one person in several departments). Without it
     *        there is no column "Anteil".
     */
    public function __construct(
        public string $id,
        public string $titel,
        /** Header of the first column. */
        public string $merkmal,
        public array $reihen,
        public array $zeilen,
        public Diagramm $diagramm,
        public ?AuswertungsZeile $zusammen = null,
        /** The values are integer cent. */
        public bool $geld = false,
        /** One or two sentences under the title: how it is counted. */
        public string $erklaerung = '',
        public ?string $anzahlTitel = null,
        public ?string $zusatzTitel = null,
    ) {
    }

    /** True when every value is 0 - the page then says so instead of a table. */
    public function istLeer(): bool
    {
        foreach ($this->zeilen as $zeile) {
            if ($zeile->summe() !== 0) {
                return false;
            }
        }

        return true;
    }

    /** Share of the total in tenths of a percent, rounded half up; 0 without a total. */
    public function promille(int $wert): int
    {
        $gesamt = $this->zusammen?->summe() ?? 0;

        return $gesamt <= 0 ? 0 : intdiv(2000 * $wert + $gesamt, 2 * $gesamt);
    }

    /** "34,5 %" with a non-breaking space. */
    public function anteil(int $wert): string
    {
        $promille = $this->promille($wert);

        return intdiv($promille, 10) . ',' . ($promille % 10) . "\u{00A0}%";
    }
}
