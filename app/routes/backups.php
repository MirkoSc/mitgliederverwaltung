<?php

declare(strict_types=1);

use App\Admin\BackupController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The settings section Backups (App\Admin\Einstellungen\BackupAbschnitt,
 * docs/spec/04-installation-und-update.md section 6): the step chain that
 * makes a backup, its cancellation and the download. Right `admin.system`;
 * the chain answers JSON to public/js/backups.js. The download takes
 * nothing but a backup file name (BackupService::path()), so no path can
 * be smuggled through {name}.
 */
return static function (Routen $routen): void {
    $system = Zugriff::recht(Permission::AdminSystem);

    $routen->post('/admin/backups', $system->alsApi(), static fn(Request $r): ResponseInterface => BackupController::aus($routen->dienste())->starten());
    $routen->post('/admin/backups/schritt', $system->alsApi(), static fn(Request $r): ResponseInterface => BackupController::aus($routen->dienste())->schritt());
    $routen->post('/admin/backups/abbrechen', $system, static fn(Request $r): Response => BackupController::aus($routen->dienste())->abbrechen());
    $routen->get(
        '/admin/backups/{name}',
        $system,
        static fn(Request $r, array $p): ResponseInterface => BackupController::aus($routen->dienste())->download($r, $p),
    );
};
