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
 * Placeholder of the fees page (issue #1) - issues #29 and #30 replace it
 * with the year's sequence of annual fees and direct debit
 * (docs/spec/08-beitraege.md). Admin only.
 */
return static function (Routen $routen): void {
    $routen->get(Navigation::BEITRAEGE, Zugriff::recht(Permission::BeitraegeVerwalten), static fn(Request $r): Response => Response::html(
        $routen->dienste()->view->render('platzhalter', [
            'title' => 'Beiträge',
            'text' => 'Hier läuft bald das Beitragsjahr ab: Jahresbeiträge erzeugen, Einzug zusammenstellen, '
                . 'Lastschriftdatei herunterladen, Einzug bestätigen. Darunter die offenen Beiträge.',
        ], Area::App),
    ));
};
