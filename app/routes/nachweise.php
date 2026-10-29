<?php

declare(strict_types=1);

use App\App\NachweisController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * Proofs of a member: note one with a file, download the file, delete
 * (docs/spec/07-mitglieder.md sections 5 and 9, issue #23). Both roles note
 * and see; only the admin deletes.
 */
return static function (Routen $routen): void {
    $controller = static fn(): NachweisController => NachweisController::aus($routen->dienste());
    $basis = '/app/mitglieder/{id:[0-9]+}/nachweise';

    $routen->post($basis, Zugriff::recht(Permission::MitgliederBearbeiten), static fn(Request $r, array $p): Response => $controller()->anlegen($r, $p));
    $routen->get($basis . '/{nachweis:[0-9]+}/datei', Zugriff::recht(Permission::MitgliederSehen), static fn(Request $r, array $p): ResponseInterface => $controller()->datei($r, $p));
    $routen->post($basis . '/{nachweis:[0-9]+}/loeschen', Zugriff::recht(Permission::MitgliederLoeschen), static fn(Request $r, array $p): Response => $controller()->loeschen($r, $p));
};
