<?php

declare(strict_types=1);

namespace App\Service\Statistik;

/**
 * The age groups of the page "Statistik" (docs/spec/09-listen-und-exporte.md
 * section 7) - defined once, used by the member and the fee statistics.
 *
 * Ages are whole years (App\Support\Alter) and both bounds are inclusive:
 * "15–18" is 15, 16, 17 and 18. An age below 0 (born after the key date)
 * counts as 0. "Unter 18" (Kinder und Jugendliche) is its own figure and
 * does not follow the groups.
 */
final class Altersgruppen
{
    /** Label => [from, to]; to null = open. */
    public const array GRUPPEN = [
        '0–6' => [0, 6],
        '7–14' => [7, 14],
        '15–18' => [15, 18],
        '19–26' => [19, 26],
        '27–40' => [27, 40],
        '41–60' => [41, 60],
        '61–70' => [61, 70],
        '71 und älter' => [71, null],
    ];

    /**
     * Stages of life: Kinder below 14 (as the law on minors counts), adults
     * from 18, Senioren from the pension age the fee hints use (67).
     */
    public const array LEBENSPHASEN = [
        'Kinder (0–13)' => [0, 13],
        'Jugendliche (14–17)' => [14, 17],
        'Erwachsene (18–66)' => [18, 66],
        'Senioren (ab 67)' => [67, null],
    ];

    public const string OHNE_GEBURTSDATUM = 'ohne Geburtsdatum';

    /** Kinder und Jugendliche: younger than this. */
    public const int VOLLJAEHRIG = 18;

    /** The label of the age group; null age => OHNE_GEBURTSDATUM. */
    public static function gruppe(?int $alter): string
    {
        return self::einordnen($alter, self::GRUPPEN);
    }

    public static function lebensphase(?int $alter): string
    {
        return self::einordnen($alter, self::LEBENSPHASEN);
    }

    /** @param array<string, array{int, int|null}> $gruppen */
    private static function einordnen(?int $alter, array $gruppen): string
    {
        if ($alter === null) {
            return self::OHNE_GEBURTSDATUM;
        }
        $alter = max(0, $alter);
        foreach ($gruppen as $name => [$von, $bis]) {
            if ($alter >= $von && ($bis === null || $alter <= $bis)) {
                return $name;
            }
        }

        // Unreachable: the groups start at 0 and the last one is open.
        return self::OHNE_GEBURTSDATUM;
    }
}
