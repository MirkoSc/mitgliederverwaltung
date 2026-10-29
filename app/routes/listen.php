<?php

declare(strict_types=1);

use App\App\ListenController;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Navigation;

/**
 * The page "Listen" (docs/spec/09-listen-und-exporte.md): the selection of
 * every list the account may see (found by App\Listen\Listen), one list
 * with filters, sorting and paging, and its export as xlsx or csv. The
 * export is a POST: it writes an audit entry, so a link prefetched by a
 * browser must not trigger it, and the CSRF check covers it.
 */
return static function (Routen $routen): void {
    $recht = Zugriff::recht(Permission::ListenSehen);
    $controller = static fn(): ListenController => ListenController::aus($routen->dienste());

    $routen->get(Navigation::LISTEN, $recht, static fn(Request $r): Response => $controller()->uebersicht());
    $routen->get(Navigation::LISTEN . '/{liste:[a-z0-9-]+}', $recht, static fn(Request $r, array $p): Response => $controller()->anzeigen($r, $p['liste']));
    $routen->post(Navigation::LISTEN . '/{liste:[a-z0-9-]+}/export', $recht, static fn(Request $r, array $p): ResponseInterface => $controller()->exportieren($r, $p['liste']));
};
