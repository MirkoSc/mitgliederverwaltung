<?php

declare(strict_types=1);

use App\App\FamilienController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The page "Familien" (issue #92, docs/spec/07-mitglieder.md section 3):
 * every role that sees members sees the families (mitglieder.sehen); every
 * change needs mitglieder.bearbeiten (Admin and Vorstand, 07 section 9).
 *
 * The page answers GET and POST like the members page: the search texts
 * (a family name is a surname) only travel in the body.
 */
return static function (Routen $routen): void {
    $sehen = Zugriff::recht(Permission::MitgliederSehen);
    $aendern = Zugriff::recht(Permission::MitgliederBearbeiten);
    $controller = static fn(): FamilienController => FamilienController::aus($routen->dienste());
    $seite = static fn(Request $r): Response => $controller()->seite($r);
    $basis = FamilienController::PFAD . '/{id:[0-9]+}';

    $routen->get(FamilienController::PFAD, $sehen, $seite);
    $routen->post(FamilienController::PFAD, $sehen, $seite);
    $routen->post(FamilienController::PFAD . '/neu', $aendern, static fn(Request $r): Response => $controller()->neu($r));
    $routen->post($basis . '/umbenennen', $aendern, static fn(Request $r, array $p): Response => $controller()->umbenennen($r, $p));
    $routen->post($basis . '/loeschen', $aendern, static fn(Request $r, array $p): Response => $controller()->loeschen($r, $p));
    $routen->post($basis . '/hinzufuegen', $aendern, static fn(Request $r, array $p): Response => $controller()->hinzufuegen($r, $p));
    $routen->post($basis . '/rolle', $aendern, static fn(Request $r, array $p): Response => $controller()->rolle($r, $p));
    $routen->post($basis . '/zahler', $aendern, static fn(Request $r, array $p): Response => $controller()->zahler($r, $p));
    $routen->post($basis . '/entfernen', $aendern, static fn(Request $r, array $p): Response => $controller()->entfernen($r, $p));
    $routen->post($basis . '/anschrift', $aendern, static fn(Request $r, array $p): Response => $controller()->anschrift($r, $p));
};
