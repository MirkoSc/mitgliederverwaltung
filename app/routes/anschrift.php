<?php

declare(strict_types=1);

use App\App\AnschriftController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * Address suggestions while typing in the member form (issue #127). Only
 * whoever may edit members fills that form, so mitglieder.bearbeiten; a
 * JSON route for fetch(). POST - the typed text stays out of the URL - and
 * so CSRF-checked like every POST route.
 */
return static function (Routen $routen): void {
    $routen->post(
        AnschriftController::PFAD,
        Zugriff::recht(Permission::MitgliederBearbeiten)->alsApi(),
        static fn(Request $r): Response => AnschriftController::aus($routen->dienste())->vorschlaege($r),
    );
};
