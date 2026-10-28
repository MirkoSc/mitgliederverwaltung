<?php

declare(strict_types=1);

namespace App\Support;

use App\Dienste;
use App\Domain\Permission;

/**
 * Common shape of everything an issue adds as a class of its own and the
 * application finds by itself (App\Support\Erweiterungen): settings
 * sections, lists, member tabs and member sections
 * (docs/spec/05-entwicklung.md section 6).
 */
interface Erweiterung
{
    /** Built per request; take what is needed from the services. */
    public static function erzeugen(Dienste $dienste): self;

    /**
     * Short, unique, lowercase with hyphens - used in URLs and as HTML id
     * (e.g. "geburtstage").
     */
    public function schluessel(): string;

    /** As shown to the user, German. */
    public function titel(): string;

    /** Display order, ascending; ties are sorted by title. */
    public function reihenfolge(): int;

    /**
     * The right needed to see it. Only courtesy for the display - every
     * route behind it declares and checks its right itself.
     */
    public function recht(): Permission;
}
