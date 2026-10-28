<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The way in: "/" and "/app" lead to the first page the account may open
 * (docs/spec/06-oberflaeche.md section 2 - Mitglieder for both roles).
 */
return static function (Routen $routen): void {
    // Public, and nothing but a redirect: the guard behind /app sends an
    // anonymous visitor on to the login.
    $routen->get('/', Zugriff::oeffentlich(), static fn(Request $r): Response => Response::redirect('/app'));

    $routen->get('/app', Zugriff::angemeldet(), static function (Request $r) use ($routen): Response {
        $konto = $routen->dienste()->view->konto();

        return Response::redirect($konto === null ? Navigation::MITGLIEDER : Navigation::startseiteFuer($konto));
    });
};
