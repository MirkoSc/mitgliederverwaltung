<?php

/**
 * Returns the closure that builds the attributes of a link which swaps the
 * whole members area (list and opened member). The address in the history is
 * the GET one: it never holds the search text.
 *
 * Shared by ergebnis.php (sorting, opening a member) and detail.php (back to
 * the list, tabs); use it as `$bereichLink = require __DIR__ . '/bereich-link.php';`.
 */

return static fn(string $ziel, string $swap = 'outerHTML show:window:top'): string => 'href="' . e($ziel) . '" hx-post="' . e($ziel)
    . '" hx-include="#mitglieder-form" hx-target="#mitglieder-bereich" hx-swap="' . $swap . '" hx-push-url="' . e($ziel) . '"';
