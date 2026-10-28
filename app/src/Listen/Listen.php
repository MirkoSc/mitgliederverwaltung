<?php

declare(strict_types=1);

namespace App\Listen;

use App\Support\Erweiterungen;

/** The lists of the page "Listen", found by file name (`*Liste.php`). */
final class Listen
{
    /**
     * @return Erweiterungen<Liste>
     */
    public static function registry(): Erweiterungen
    {
        return new Erweiterungen(Liste::class, __DIR__, __NAMESPACE__, 'Liste');
    }
}
