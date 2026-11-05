<?php

declare(strict_types=1);

namespace App\Service\Statistik;

use App\Domain\Geschlecht;

/** Shared rows and series of the statistics (09 section 7). */
final class Merkmale
{
    /** The gender series, name => colour slot; "ohne Angabe" is grey. */
    public const array GESCHLECHTER = ['weiblich' => 1, 'männlich' => 2, 'divers' => 3, 'ohne Angabe' => 4];

    /** Index into GESCHLECHTER. */
    public static function geschlecht(?Geschlecht $geschlecht): int
    {
        return match ($geschlecht) {
            Geschlecht::W => 0,
            Geschlecht::M => 1,
            Geschlecht::D => 2,
            null => 3,
        };
    }

    public static function geschlechtName(?Geschlecht $geschlecht): string
    {
        return array_keys(self::GESCHLECHTER)[self::geschlecht($geschlecht)];
    }

    /** @return list<string> the age groups, then "ohne Geburtsdatum" */
    public static function altersgruppen(): array
    {
        return [...array_keys(Altersgruppen::GRUPPEN), Altersgruppen::OHNE_GEBURTSDATUM];
    }

    public static function familie(?int $familieId): string
    {
        return $familieId === null ? 'einzeln' : 'in einer Familie';
    }
}
