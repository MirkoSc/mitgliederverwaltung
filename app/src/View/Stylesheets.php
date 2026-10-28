<?php

declare(strict_types=1);

namespace App\View;

/**
 * The stylesheets every page loads: the design system public/css/app.css
 * first, then every file in public/css/teile/ in name order
 * (docs/spec/05-entwicklung.md section 6). An issue adds its own styles as a
 * file of its own there instead of editing the design system, and the layout
 * picks it up without a change anywhere else.
 */
final class Stylesheets
{
    public const string DESIGNSYSTEM = '/css/app.css';

    public const string TEILE = '/css/teile';

    /**
     * @return list<string> URLs below the docroot
     */
    public static function aus(string $publicDir): array
    {
        $teile = glob($publicDir . self::TEILE . '/*.css') ?: [];
        sort($teile, SORT_STRING);

        return [
            self::DESIGNSYSTEM,
            ...array_map(static fn(string $datei): string => self::TEILE . '/' . basename($datei), $teile),
        ];
    }
}
