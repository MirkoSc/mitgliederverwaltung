<?php

declare(strict_types=1);

use App\App\EhrungenController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\Listen\EhrungenListe;

/**
 * "geehrt" of the honours list (docs/spec/09-listen-und-exporte.md section 2,
 * issue #26): note it or take it back. Both roles.
 */
return static function (Routen $routen): void {
    $routen->post(
        EhrungenListe::PFAD_VERMERK,
        Zugriff::recht(Permission::ListenSehen),
        static fn(Request $r): Response => EhrungenController::aus($routen->dienste())->vermerken($r),
    );
};
