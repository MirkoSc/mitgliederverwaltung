<?php

declare(strict_types=1);

use App\App\HilfeController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\Service\Hilfe\Anleitungen;
use App\View\Navigation;

/**
 * The help (docs/spec/12-hilfe.md section 1), from the user menu: the
 * overview and one guide, read only, no member data.
 *
 * Both routes are "angemeldet" on purpose: each guide names the right its
 * steps need, and that right changes from guide to guide - so the
 * controller checks it per guide and answers 404 for one the role may not
 * carry out (the guides are not secret; the filter only spares users
 * guides they cannot follow).
 */
return static function (Routen $routen): void {
    $controller = static fn(): HilfeController => HilfeController::aus($routen->dienste());

    $routen->get(Navigation::HILFE, Zugriff::angemeldet(), static fn(Request $r): Response => $controller()->uebersicht());
    $routen->get(
        Navigation::HILFE . '/{slug:' . Anleitungen::SLUG . '}',
        Zugriff::angemeldet(),
        static fn(Request $r, array $p): Response => $controller()->anleitung($p['slug']),
    );
};
