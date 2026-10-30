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
 * Both roles create and edit; only the admin deletes (07 section 9).
 */
return static function (Routen $routen): void {
    $bearbeiten = Zugriff::recht(Permission::MitgliederBearbeiten);
    $loeschen = Zugriff::recht(Permission::MitgliederLoeschen);
    $controller = static fn(): MitgliedController => MitgliedController::aus($routen->dienste());

    $routen->get(MitgliedController::PFAD_NEU, $bearbeiten, static fn(Request $r): Response => $controller()->neu($r));
    $routen->post(MitgliedController::PFAD_NEU, $bearbeiten, static fn(Request $r): Response => $controller()->anlegen($r));
    $routen->post('/app/mitglieder/{id:[0-9]+}', $bearbeiten, static fn(Request $r, array $p): Response => $controller()->speichern($r, $p));
    $routen->get('/app/mitglieder/{id:[0-9]+}/loeschen', $loeschen, static fn(Request $r, array $p): Response => $controller()->loeschenFragen($r, $p));
    $routen->post('/app/mitglieder/{id:[0-9]+}/loeschen', $loeschen, static fn(Request $r, array $p): Response => $controller()->loeschen($r, $p));
};
