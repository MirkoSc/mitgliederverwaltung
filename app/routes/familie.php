<?php

declare(strict_types=1);

use App\App\FamilieController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;

/**
 * The family of a member: choose, join, create, change the role, leave
 * (docs/spec/07-mitglieder.md sections 3 and 9, issue #20). Both roles.
 */
return static function (Routen $routen): void {
    $recht = Zugriff::recht(Permission::MitgliederBearbeiten);
    $controller = static fn(): FamilieController => FamilieController::aus($routen->dienste());
    $basis = '/app/mitglieder/{id:[0-9]+}/familie';

    $routen->get($basis, $recht, static fn(Request $r, array $p): Response => $controller()->waehlen($r, $p));
    $routen->post($basis . '/beitreten', $recht, static fn(Request $r, array $p): Response => $controller()->beitreten($r, $p));
    $routen->post($basis . '/neu', $recht, static fn(Request $r, array $p): Response => $controller()->neu($r, $p));
    $routen->post($basis . '/rolle', $recht, static fn(Request $r, array $p): Response => $controller()->rolleAendern($r, $p));
    $routen->post($basis . '/umbenennen', $recht, static fn(Request $r, array $p): Response => $controller()->umbenennen($r, $p));
    $routen->post($basis . '/verlassen', $recht, static fn(Request $r, array $p): Response => $controller()->verlassen($r, $p));
};
