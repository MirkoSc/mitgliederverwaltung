<?php

declare(strict_types=1);

namespace App\Listen;

/** The kinds of filter a list can offer. */
enum FilterArt
{
    /** One of a fixed set of options (select). */
    case Auswahl;

    /** A checkbox; unchecked means false, so it cannot default to true. */
    case Haken;

    /** A year (number field). */
    case Jahr;

    /** A calendar day (date field), kept as "Y-m-d". */
    case Datum;
}
