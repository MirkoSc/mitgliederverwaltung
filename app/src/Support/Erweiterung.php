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
     * The right needed to see it. For tabs, sections and lists without a
     * route of their own (a member's tab is a state of the members page)
     * this IS the server-side check: the page shows only what the account
     * may see (Erweiterungen::alle() with the account). Forms inside post
     * to routes that declare and check their right themselves.
     */
    public function recht(): Permission;
}
