<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Support\Erweiterungen;

/** The sections of the settings page, found by file name (`*Abschnitt.php`). */
final class Abschnitte
{
    /**
     * @return Erweiterungen<Abschnitt>
     */
    public static function registry(): Erweiterungen
    {
        return new Erweiterungen(Abschnitt::class, __DIR__, __NAMESPACE__, 'Abschnitt');
    }
}
