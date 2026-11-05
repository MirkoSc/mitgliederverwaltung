<?php

declare(strict_types=1);

use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\PublicPages\ManifestController;

/**
 * The installable app (docs/spec/06-oberflaeche.md section 6): the web app
 * manifest, public - a browser fetches it without cookies, and it names
 * only the club and its logo. The service worker /sw.js is a static file
 * (App\Http\StaticFileHandler).
 */
return static function (Routen $routen): void {
    $routen->get(ManifestController::PFAD, Zugriff::oeffentlich(), static fn(): Response
        => new ManifestController($routen->dienste())->anzeigen());
};
