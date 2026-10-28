<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dates as the application shows and reads them. Everything is local time,
 * Europe/Berlin (CLAUDE.md section 5): DATE columns hold a calendar day,
 * DATETIME columns local time (docs/spec/03-datenhaltung.md section 1).
 */
final class Datum
{
    public const string ZEITZONE = 'Europe/Berlin';

    /** "31.12.2026"; '' for null and ''. */
    public static function anzeigen(\DateTimeInterface|string|null $wert): string
    {
        $datum = self::lesen($wert);

        return $datum === null ? (is_string($wert) ? $wert : '') : $datum->format('d.m.Y');
    }

    /** "31.12.2026, 14:05" in local time; '' for null and ''. */
    public static function zeitpunktAnzeigen(\DateTimeInterface|string|null $wert): string
    {
        $datum = self::lesen($wert);

        return $datum === null ? (is_string($wert) ? $wert : '') : $datum->format('d.m.Y, H:i');
    }

    /**
     * A calendar day from what a person types or a date field sends:
     * "31.12.2026", "1.2.2026" or "2026-12-31". Null for anything else,
     * including days that do not exist (31.02.).
     */
    public static function ausEingabe(string $eingabe): ?\DateTimeImmutable
    {
        $text = trim($eingabe);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', $text, $m) === 1) {
            [$tag, $monat, $jahr] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $text, $m) === 1) {
            [$jahr, $monat, $tag] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if ($jahr < 1 || !checkdate($monat, $tag, $jahr)) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $jahr, $monat, $tag), self::zone());
    }

    /** For a DATE column: "2026-12-31". */
    public static function fuerDatenbank(\DateTimeInterface $datum): string
    {
        return $datum->format('Y-m-d');
    }

    /** For a DATETIME column: local time, "2026-12-31 14:05:00". */
    public static function zeitpunktFuerDatenbank(\DateTimeInterface $zeitpunkt): string
    {
        return \DateTimeImmutable::createFromInterface($zeitpunkt)->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    /**
     * A DATE or DATETIME value from the database (local time), or a
     * DateTimeInterface in any zone, as local time; null when it is neither.
     */
    private static function lesen(\DateTimeInterface|string|null $wert): ?\DateTimeImmutable
    {
        if ($wert instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($wert)->setTimezone(self::zone());
        }
        if ($wert === null || $wert === '') {
            return null;
        }

        foreach (['!Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $datum = \DateTimeImmutable::createFromFormat($format, $wert, self::zone());
            if ($datum !== false && $datum->format(ltrim($format, '!')) === $wert) {
                return $datum;
            }
        }

        return null;
    }

    private static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZEITZONE);
    }
}
