<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Conversions between database rows and domain values, shared by the
 * repositories of #15 (docs/spec/03-datenhaltung.md section 1: DATE is a
 * calendar day, DATETIME local time, JSON for lists).
 *
 * @internal for App\Repository only
 */
final class Zeilen
{
    /** A DATE column, midnight local time; null for NULL. */
    public static function datum(mixed $wert): ?\DateTimeImmutable
    {
        if ($wert === null || $wert === '') {
            return null;
        }
        $datum = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $wert);

        return $datum === false ? null : $datum;
    }

    /** A DATETIME column (local time). */
    public static function zeitpunkt(mixed $wert): \DateTimeImmutable
    {
        $zeit = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $wert);
        if ($zeit === false) {
            throw new \UnexpectedValueException('Not a DATETIME value.');
        }

        return $zeit;
    }

    public static function zeitpunktOderNull(mixed $wert): ?\DateTimeImmutable
    {
        return $wert === null ? null : self::zeitpunkt($wert);
    }

    /** For a DATE column. */
    public static function fuerDatum(?\DateTimeInterface $datum): ?string
    {
        return $datum?->format('Y-m-d');
    }

    /** For a DATETIME column: local time from PHP, not NOW() (the session may run in UTC). */
    public static function fuerZeitpunkt(?\DateTimeInterface $zeit): string
    {
        return \DateTimeImmutable::createFromInterface($zeit ?? new \DateTimeImmutable())
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    public static function idOderNull(mixed $wert): ?int
    {
        return $wert === null ? null : (int) $wert;
    }

    public static function json(mixed $wert): string
    {
        return json_encode($wert, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<mixed>
     */
    public static function jsonArray(mixed $wert): array
    {
        if ($wert === null || $wert === '') {
            return [];
        }
        $daten = json_decode((string) $wert, true, flags: JSON_THROW_ON_ERROR);

        return is_array($daten) ? $daten : [];
    }

    /**
     * Runs $arbeit in a transaction - or inside the caller's, if one is
     * already open (an import step saves many members in one), so the
     * repositories can be combined freely.
     *
     * @template T
     * @param \Closure(): T $arbeit
     * @return T
     */
    public static function transaktion(\PDO $pdo, \Closure $arbeit): mixed
    {
        if ($pdo->inTransaction()) {
            return $arbeit();
        }

        $pdo->beginTransaction();
        try {
            $ergebnis = $arbeit();
            $pdo->commit();
        } catch (\Throwable $fehler) {
            $pdo->rollBack();

            throw $fehler;
        }

        return $ergebnis;
    }

    /**
     * "?, ?, ?" for an IN list of that many values.
     */
    public static function platzhalter(int $anzahl): string
    {
        return implode(', ', array_fill(0, max(1, $anzahl), '?'));
    }
}
