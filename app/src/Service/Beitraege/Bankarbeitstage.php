<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

/**
 * Bank working days for the due date of a direct debit
 * (docs/spec/08-beitraege.md section 3): the TARGET calendar that SEPA
 * runs on - every day except Saturday, Sunday, New Year's Day, Good Friday,
 * Easter Monday, 1 May, 25 and 26 December. German public holidays beyond
 * those do not stop SEPA.
 *
 * Pure date arithmetic on calendar days (no time of day, no time zone
 * conversion); Easter by the Gregorian computus, without ext-calendar.
 */
final class Bankarbeitstage
{
    private function __construct()
    {
        // Static utility, no instances.
    }

    public static function istBankarbeitstag(\DateTimeImmutable $tag): bool
    {
        if ((int) $tag->format('N') >= 6) {
            return false;
        }
        $monatTag = $tag->format('m-d');
        if (in_array($monatTag, ['01-01', '05-01', '12-25', '12-26'], true)) {
            return false;
        }
        $ostern = self::ostersonntag((int) $tag->format('Y'));
        $datum = $tag->format('Y-m-d');

        return $datum !== $ostern->modify('-2 days')->format('Y-m-d')
            && $datum !== $ostern->modify('+1 day')->format('Y-m-d');
    }

    /** The first bank working day after $tag. */
    public static function naechster(\DateTimeImmutable $tag): \DateTimeImmutable
    {
        $tag = $tag->setTime(0, 0);
        do {
            $tag = $tag->modify('+1 day');
        } while (!self::istBankarbeitstag($tag));

        return $tag;
    }

    /**
     * The proposed due date: the next bank working day + 1 - one working
     * day more than SEPA needs, so the file may still go to the bank late
     * in the day.
     */
    public static function vorschlag(\DateTimeImmutable $heute): \DateTimeImmutable
    {
        return self::naechster(self::naechster($heute));
    }

    /** Easter Sunday of $jahr (anonymous Gregorian algorithm). */
    public static function ostersonntag(int $jahr): \DateTimeImmutable
    {
        $a = $jahr % 19;
        $b = intdiv($jahr, 100);
        $c = $jahr % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $monat = intdiv($h + $l - 7 * $m + 114, 31);
        $tag = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $monat, $tag));
    }
}
