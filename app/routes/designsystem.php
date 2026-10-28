<?php

declare(strict_types=1);

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Area;

/**
 * The pattern page of the design system (issue #9,
 * docs/spec/06-oberflaeche.md section 3): every building block on one URL,
 * so a new page copies markup that exists instead of inventing classes, and
 * so 360 px, PC, light, dark and the Barrierefrei-Modus can be checked in
 * one place. Admin only - it shows nothing but made-up examples, but it is a
 * tool for whoever builds or checks the pages, not for the board.
 *
 * Query parameters only steer the examples, nothing is written:
 * - mitglied=<1..3> opens an example member next to the list
 * - reiter=daten|beitraege|verlauf picks its tab
 * - barrierefrei=1 shows the page in the Barrierefrei-Modus without
 *   touching the account's setting
 */
return static function (Routen $routen): void {
    $routen->get('/admin/designsystem', Zugriff::recht(Permission::AdminEinstellungen), static function (Request $r) use ($routen): Response {
        $mitglied = (int) $r->feld('mitglied');
        $reiter = $r->feld('reiter');

        return Response::html($routen->dienste()->view->render('admin/designsystem', [
            'title' => 'Designsystem',
            'gewaehlt' => in_array($mitglied, [1, 2, 3], true) ? $mitglied : null,
            'reiter' => in_array($reiter, ['daten', 'beitraege', 'verlauf'], true) ? $reiter : 'daten',
            'barrierefreiVorschau' => $r->feld('barrierefrei') === '1',
        ], Area::Admin));
    });
};
