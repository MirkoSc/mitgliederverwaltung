<?php

declare(strict_types=1);

use App\Admin\StammdatenController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The forms of the settings sections Abteilungen, Funktionen, Beitragsarten,
 * Runde Geburtstage and Ehrungsstufen (admin only).
 */
return static function (Routen $routen): void {
    $admin = Zugriff::recht(Permission::AdminEinstellungen);

    foreach (['abteilungen', 'funktionen', 'beitragsarten', 'ehrungsstufen'] as $aktion) {
        $routen->post('/admin/' . $aktion, $admin, static fn(Request $r): Response => StammdatenController::aus($routen->dienste())->$aktion($r));
    }
    $routen->post('/admin/geburtstage', $admin, static fn(Request $r): Response => StammdatenController::aus($routen->dienste())->rundeGeburtstage($r));
};
