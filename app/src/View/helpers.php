<?php

declare(strict_types=1);

use App\Support\Datum;
use App\Support\Geld;

/**
 * Template helpers, loaded through Composer's "files" autoload. Small on
 * purpose: the logic lives in App\Support\Datum and App\Support\Geld, these
 * only give the templates short names.
 */

/**
 * Escapes a value for safe HTML output.
 */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * A date as "31.12.2026" - from a DateTimeInterface or a database DATE /
 * DATETIME string; '' for null and ''.
 */
function datum(\DateTimeInterface|string|null $wert): string
{
    return Datum::anzeigen($wert);
}

/**
 * A point in time as "31.12.2026, 14:05" in local time (Europe/Berlin).
 */
function zeitpunkt(\DateTimeInterface|string|null $wert): string
{
    return Datum::zeitpunktAnzeigen($wert);
}

/**
 * An amount in cent as "1.234,56 €" - never a float (CLAUDE.md section 5).
 */
function geld(?int $cent): string
{
    return $cent === null ? '' : Geld::anzeigen($cent);
}
