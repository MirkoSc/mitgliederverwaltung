<?php

declare(strict_types=1);

use App\App\MitgliedController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * Create, change and delete a member (docs/spec/07-mitglieder.md sections 1
 * and 9, issue #19). Opening a member is a state of the members page
 * (app/routes/mitglieder.php); its tab "Daten" posts here.
 *
 * Both roles create and edit and enter or take back a member's leaving;
 * only the admin deletes, and only a former member (07 sections 1 and 9).
 */
return static function (Routen $routen): void {
    $bearbeiten = Zugriff::recht(Permission::MitgliederBearbeiten);
    $loeschen = Zugriff::recht(Permission::MitgliederLoeschen);
    $controller = static fn(): MitgliedController => MitgliedController::aus($routen->dienste());

    $routen->get(MitgliedController::PFAD_NEU, $bearbeiten, static fn(Request $r): Response => $controller()->neu($r));
    $routen->post(MitgliedController::PFAD_NEU, $bearbeiten, static fn(Request $r): Response => $controller()->anlegen($r));
    $routen->post('/app/mitglieder/{id:[0-9]+}', $bearbeiten, static fn(Request $r, array $p): Response => $controller()->speichern($r, $p));
    $routen->get('/app/mitglieder/{id:[0-9]+}/austritt', $bearbeiten, static fn(Request $r, array $p): Response => $controller()->austrittFragen($r, $p));
    $routen->post('/app/mitglieder/{id:[0-9]+}/austritt', $bearbeiten, static fn(Request $r, array $p): Response => $controller()->austritt($r, $p));
    $routen->get('/app/mitglieder/{id:[0-9]+}/austritt-zuruecknehmen', $bearbeiten, static fn(Request $r, array $p): Response => $controller()->zuruecknehmenFragen($r, $p));
    $routen->post('/app/mitglieder/{id:[0-9]+}/austritt-zuruecknehmen', $bearbeiten, static fn(Request $r, array $p): Response => $controller()->zuruecknehmen($r, $p));
    $routen->get('/app/mitglieder/{id:[0-9]+}/loeschen', $loeschen, static fn(Request $r, array $p): Response => $controller()->loeschenFragen($r, $p));
    $routen->post('/app/mitglieder/{id:[0-9]+}/loeschen', $loeschen, static fn(Request $r, array $p): Response => $controller()->loeschen($r, $p));
};
