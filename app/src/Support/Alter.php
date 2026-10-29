<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Age in whole years on a given day (docs/spec/07-mitglieder.md section 6:
 * "Alter < 67", "Alter ≥ 18"). Only the calendar day counts, never the time.
 *
 * Born on 29 February: in a year without one the birthday is 28 February -
 * the same rule as the birthday list (docs/spec/09-listen-und-exporte.md
 * section 2), so a member does not turn 18 on a different day in the hints
 * than in the list.
 */
final class Alter
{
    public static function am(\DateTimeInterface $geburtsdatum, \DateTimeInterface $stichtag): int
    {
        $jahre = (int) $stichtag->format('Y') - (int) $geburtsdatum->format('Y');
        if (self::geburtstagImJahr($geburtsdatum, (int) $stichtag->format('Y')) > $stichtag->format('m-d')) {
            $jahre--;
        }

        return $jahre;
    }

    /**
     * "MM-DD" of the birthday in the given year - 29 February becomes
     * 28 February in a year without it.
     */
    public static function geburtstagImJahr(\DateTimeInterface $geburtsdatum, int $jahr): string
    {
        $tag = $geburtsdatum->format('m-d');
        if ($tag === '02-29' && !checkdate(2, 29, $jahr)) {
            return '02-28';
        }

        return $tag;
    }
}
