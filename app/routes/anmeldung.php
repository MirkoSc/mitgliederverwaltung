<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\PublicPages\AnmeldungController;

/**
 * The way in (docs/spec/02-sicherheit.md section 3): login, logout,
 * "Passwort vergessen", accepting an invitation. All public (E-F3), all
 * below /anmelden except the logout; every POST carries the CSRF token of
 * the session the form page started.
 */
return static function (Routen $routen): void {
    $oeffentlich = Zugriff::oeffentlich();
    $c = static fn(): AnmeldungController => AnmeldungController::aus($routen->dienste());

    $routen->get('/anmelden', $oeffentlich, static fn(Request $r): Response => $c()->form($r));
    $routen->post('/anmelden', $oeffentlich, static fn(Request $r): Response => $c()->anmelden($r));
    $routen->post('/abmelden', $oeffentlich, static fn(Request $r): Response => $c()->abmelden($r));

    $routen->get(AnmeldungController::VERGESSEN, $oeffentlich, static fn(Request $r): Response => $c()->vergessenForm($r));
    $routen->post(AnmeldungController::VERGESSEN, $oeffentlich, static fn(Request $r): Response => $c()->vergessen($r));
    $routen->get(AnmeldungController::PASSWORT_NEU, $oeffentlich, static fn(Request $r): Response => $c()->neuForm($r));
    $routen->post(AnmeldungController::PASSWORT_NEU, $oeffentlich, static fn(Request $r): Response => $c()->neu($r));
    $routen->get(AnmeldungController::EINLADUNG, $oeffentlich, static fn(Request $r): Response => $c()->einladungForm($r));
    $routen->post(AnmeldungController::EINLADUNG, $oeffentlich, static fn(Request $r): Response => $c()->einladung($r));
};
