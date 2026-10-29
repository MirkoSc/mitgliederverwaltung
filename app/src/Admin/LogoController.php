<?php

declare(strict_types=1);

namespace App\Admin;

use App\Dienste;
use App\Http\FileResponse;
use App\Http\ResponseInterface;
use App\Http\Response;
use App\Service\Verein\LogoService;

/**
 * Delivers the club logo (docs/spec/11-vereinsdaten.md section 2). Public:
 * the login page and the favicon show it before anyone is logged in, and a
 * logo is public information. Without a logo, the neutral placeholder.
 */
final readonly class LogoController
{
    public function __construct(private Dienste $dienste)
    {
    }

    public function anzeigen(string $groesse, bool $versioniert): ResponseInterface
    {
        if (!in_array($groesse, LogoService::groessen(), true)) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Nicht gefunden.');
        }

        try {
            $datei = LogoService::aus($this->dienste)->datei($groesse);
        } catch (\Throwable) {
            $datei = null; // no database yet: the placeholder, not an error page
        }

        // ?v=<upload time> changes with every logo, so a versioned URL may
        // be cached for good; the same URL without it must be revalidated.
        $cache = $versioniert && $datei !== null ? 'public, max-age=31536000, immutable' : 'no-cache';
        if ($datei === null) {
            return new FileResponse(
                $this->dienste->paths->publicDir() . \App\View\View::LOGO_PLATZHALTER,
                ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-cache'],
            );
        }

        return new FileResponse($datei, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) filesize($datei),
            'Cache-Control' => $cache,
        ]);
    }
}
