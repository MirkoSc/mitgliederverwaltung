<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Support\Erweiterungen;

/**
 * Tabs (`*Reiter.php`) and sections (`*Abschnitt.php`) of an opened member,
 * found by file name.
 */
final class MitgliedErweiterungen
{
    /**
     * @return Erweiterungen<Reiter>
     */
    public static function reiter(): Erweiterungen
    {
        return new Erweiterungen(Reiter::class, __DIR__, __NAMESPACE__, 'Reiter');
    }

    /**
     * @return Erweiterungen<Abschnitt>
     */
    public static function abschnitte(): Erweiterungen
    {
        return new Erweiterungen(Abschnitt::class, __DIR__, __NAMESPACE__, 'Abschnitt');
    }
}
