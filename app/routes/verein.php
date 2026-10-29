<?php

declare(strict_types=1);

use App\Admin\LogoController;
use App\Admin\VereinController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The forms of the settings section Verein (App\Admin\Einstellungen\
 * VereinAbschnitt; admin only) and the logo route, which is public: the
 * login page and the favicon show the logo (docs/spec/11-vereinsdaten.md).
 */
return static function (Routen $routen): void {
    $admin = Zugriff::recht(Permission::AdminEinstellungen);

    $routen->post('/admin/verein', $admin, static fn(Request $r): Response => VereinController::aus($routen->dienste())->speichern($r));
    $routen->post('/admin/verein/verbaende', $admin, static fn(Request $r): Response => VereinController::aus($routen->dienste())->verbaende($r));
    $routen->post('/admin/verein/logo', $admin, static fn(Request $r): Response => VereinController::aus($routen->dienste())->logoHochladen($r));
    $routen->post('/admin/verein/logo/entfernen', $admin, static fn(Request $r): Response => VereinController::aus($routen->dienste())->logoEntfernen($r));

    $routen->get('/logo/{groesse}', Zugriff::oeffentlich(), static fn(Request $r, array $params): ResponseInterface
        => new LogoController($routen->dienste())->anzeigen($params['groesse'] ?? '', $r->feld('v') !== ''));
};
