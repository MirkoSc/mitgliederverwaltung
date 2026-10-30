<?php

declare(strict_types=1);

use App\Admin\BenutzerController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The forms of the settings section Benutzer (App\Admin\Einstellungen\
 * BenutzerAbschnitt): invite, invite again, role, Barrierefrei-Modus, lock,
 * unlock, delete.
 * Admin only (docs/spec/02-sicherheit.md section 6, `admin.benutzer`).
 */
return static function (Routen $routen): void {
    $zugriff = Zugriff::recht(Permission::AdminBenutzer);
    $controller = static fn(): BenutzerController => BenutzerController::aus($routen->dienste());

    $routen->post('/admin/benutzer', $zugriff, static fn(Request $r): Response => $controller()->einladen($r));
    $routen->post('/admin/benutzer/{id:[0-9]+}/einladung', $zugriff, static fn(Request $r, array $p): Response => $controller()->erneutEinladen($r, (int) $p['id']));
    $routen->post('/admin/benutzer/{id:[0-9]+}/rolle', $zugriff, static fn(Request $r, array $p): Response => $controller()->rolle($r, (int) $p['id']));
    $routen->post('/admin/benutzer/{id:[0-9]+}/barrierefrei', $zugriff, static fn(Request $r, array $p): Response => $controller()->barrierefrei($r, (int) $p['id']));
    $routen->post('/admin/benutzer/{id:[0-9]+}/sperren', $zugriff, static fn(Request $r, array $p): Response => $controller()->sperren($r, (int) $p['id']));
    $routen->post('/admin/benutzer/{id:[0-9]+}/entsperren', $zugriff, static fn(Request $r, array $p): Response => $controller()->entsperren($r, (int) $p['id']));
    $routen->post('/admin/benutzer/{id:[0-9]+}/loeschen', $zugriff, static fn(Request $r, array $p): Response => $controller()->loeschen($r, (int) $p['id']));
};
