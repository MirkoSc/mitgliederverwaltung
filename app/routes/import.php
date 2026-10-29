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
 * docs/spec/10-import.md sections 1 and 4): upload import.csv, start and
 * drive the chain of the first import or of the reconciliation, drop the
 * upload, empty the stock. Right `admin.import`; the chain routes answer
 * JSON to public/js/import.js and public/js/abgleich.js.
 */
return static function (Routen $routen): void {
    $import = Zugriff::recht(Permission::AdminImport);
    $controller = static fn(): ImportController => ImportController::aus($routen->dienste());

    $routen->post('/admin/import/hochladen', $import, static fn(Request $r): Response => $controller()->hochladen($r));
    $routen->post('/admin/import/starten', $import->alsApi(), static fn(Request $r): ResponseInterface => $controller()->starten());
    $routen->post('/admin/import/schritt', $import->alsApi(), static fn(Request $r): ResponseInterface => $controller()->schritt($r));
    $routen->post('/admin/import/verwerfen', $import, static fn(Request $r): Response => $controller()->verwerfen());
    $routen->post('/admin/import/abgleich/starten', $import->alsApi(), static fn(Request $r): ResponseInterface => $controller()->abgleichStarten($r));
    $routen->post('/admin/import/abgleich/schritt', $import->alsApi(), static fn(Request $r): ResponseInterface => $controller()->abgleichSchritt($r));
    $routen->post('/admin/import/abgleich/verwerfen', $import, static fn(Request $r): Response => $controller()->abgleichVerwerfen($r));
    $routen->get(ImportController::PFAD_LEEREN, $import, static fn(Request $r): Response => $controller()->leerenFragen());
    $routen->post(ImportController::PFAD_LEEREN, $import, static fn(Request $r): Response => $controller()->leeren($r));
};
