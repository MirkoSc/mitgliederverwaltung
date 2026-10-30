<?php

declare(strict_types=1);

use App\App\WohnortController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The block "Wohnort auf der Karte" at a member (issue #106): search now,
 * save or remove a search address for the map. Changes data, so it is
 * mitglieder.bearbeiten (admin and Vorstand); "Nur lesen" sees the block
 * without buttons, and this route answers it with 403. CSRF as on every
 * POST route.
 */
return static function (Routen $routen): void {
    $routen->post(
        '/app/mitglieder/{id:[0-9]+}/wohnort',
        Zugriff::recht(Permission::MitgliederBearbeiten),
        static fn(Request $r, array $p): Response => WohnortController::aus($routen->dienste())->aendern($r, $p),
    );
};
