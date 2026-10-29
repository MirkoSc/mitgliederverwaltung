<?php

declare(strict_types=1);

use App\Admin\ImportController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The settings section Import (App\Admin\Einstellungen\ImportAbschnitt,
 * docs/spec/10-import.md section 1): upload import.csv, start and drive
 * the chain, drop the upload, empty the stock. Right `admin.import`; the two
 * chain routes answer JSON to public/js/import.js.
 */
return static function (Routen $routen): void {
    $import = Zugriff::recht(Permission::AdminImport);
    $controller = static fn(): ImportController => ImportController::aus($routen->dienste());

    $routen->post('/admin/import/hochladen', $import, static fn(Request $r): Response => $controller()->hochladen($r));
    $routen->post('/admin/import/starten', $import->alsApi(), static fn(Request $r): ResponseInterface => $controller()->starten());
    $routen->post('/admin/import/schritt', $import->alsApi(), static fn(Request $r): ResponseInterface => $controller()->schritt($r));
    $routen->post('/admin/import/verwerfen', $import, static fn(Request $r): Response => $controller()->verwerfen());
    $routen->get(ImportController::PFAD_LEEREN, $import, static fn(Request $r): Response => $controller()->leerenFragen());
    $routen->post(ImportController::PFAD_LEEREN, $import, static fn(Request $r): Response => $controller()->leeren($r));
};
