<?php

declare(strict_types=1);

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Area;
use App\View\Navigation;

/**
 * Placeholder of the members page (issue #1) - issue #18 replaces it with
 * list, search and filters, issue #19 adds the member itself
 * (docs/spec/06-oberflaeche.md section 2).
 */
return static function (Routen $routen): void {
    $routen->get(Navigation::MITGLIEDER, Zugriff::recht(Permission::MitgliederSehen), static fn(Request $r): Response => Response::html(
        $routen->dienste()->view->render('platzhalter', [
            'title' => 'Mitglieder',
            'text' => 'Hier stehen bald alle Mitglieder: ein Suchfeld für Name, Mitgliedsnummer und Ort, '
                . 'darunter die Liste mit Filtern nach Abteilung und Beitragsart. Ein Klick öffnet das Mitglied.',
        ], Area::App),
    ));
};
