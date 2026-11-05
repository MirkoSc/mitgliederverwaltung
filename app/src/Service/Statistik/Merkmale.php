<?php

declare(strict_types=1);

namespace App\Service\Statistik;

use App\Domain\Geschlecht;

/** Shared rows and series of the statistics (09 section 7). */
final class Merkmale
{
    public const string OHNE_ANGABE = 'ohne Angabe';
    public const string IN_FAMILIE = 'in einer Familie';
    public const string EINZELN = 'einzeln';

    /**
     * The gender series, name => colour slot; "ohne Angabe" is grey.
     *
     * @return array<string, int>
     */
    public static function geschlechter(): array
    {
        return [
            Geschlecht::W->bezeichnung() => 1,
            Geschlecht::M->bezeichnung() => 2,
            Geschlecht::D->bezeichnung() => 3,
            self::OHNE_ANGABE => 4,
        ];
    }

    /** Index into geschlechter(). */
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
        return array_keys(self::geschlechter())[self::geschlecht($geschlecht)];
    }

    /** @return list<string> the age groups, then "ohne Geburtsdatum" */
    public static function altersgruppen(): array
    {
        return [...array_keys(Altersgruppen::GRUPPEN), Altersgruppen::OHNE_GEBURTSDATUM];
    }

    public static function familie(?int $familieId): string
    {
        return $familieId === null ? self::EINZELN : self::IN_FAMILIE;
    }
}
