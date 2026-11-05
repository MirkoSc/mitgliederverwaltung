<?php

declare(strict_types=1);

namespace App\Service\Statistik;

/**
 * How public/js/statistik.js draws an Auswertung (09 section 7). The table
 * stays the source; without JavaScript or in the Barrierefrei-Modus there
 * is only the table.
 */
enum Diagramm: string
{
    /** Vertical bars, one series. */
    case Saeulen = 'saeulen';
    /** Horizontal bars, one series - for long labels (places, departments). */
    case Balken = 'balken';
    /** Vertical bars, the series stacked. */
    case Gestapelt = 'gestapelt';
    /** Horizontal bars, the series stacked. */
    case GestapeltLiegend = 'gestapelt-liegend';
    /** Vertical bars, the series side by side. */
    case Gruppiert = 'gruppiert';
    /** Parts of a whole, at most a few rows. */
    case Ring = 'ring';
}
