<?php

declare(strict_types=1);

use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\Listen\Listen;
use App\View\Area;
use App\View\Navigation;

/**
 * The page "Listen" (docs/spec/09-listen-und-exporte.md): the selection of
 * every list the account may see, found by App\Listen\Listen. Issue #24
 * builds the list view with filters and export behind it; until then a
 * list shows what it is.
 */
return static function (Routen $routen): void {
    $recht = Zugriff::recht(Permission::ListenSehen);

    $routen->get(Navigation::LISTEN, $recht, static function (Request $r) use ($routen): Response {
        $dienste = $routen->dienste();

        return Response::html($dienste->view->render('listen/uebersicht', [
            'title' => 'Listen',
            'listen' => Listen::registry()->alle($dienste, $dienste->view->konto()),
        ], Area::App));
    });

    $routen->get(Navigation::LISTEN . '/{liste:[a-z0-9-]+}', $recht, static function (Request $r, array $p) use ($routen): Response {
        $dienste = $routen->dienste();
        // Not found and not allowed look the same: a list the account may
        // not see does not exist for it (fee list for the board, 09).
        $liste = Listen::registry()->finde($dienste, $p['liste'], $dienste->view->konto());
        if ($liste === null) {
            return Response::html($dienste->view->render('fehler', [
                'title' => 'Liste nicht gefunden',
                'meldung' => 'Diese Liste gibt es nicht. Wählen Sie eine aus der Übersicht.',
                'startseite' => Navigation::LISTEN,
            ], Area::App), 404);
        }

        return Response::html($dienste->view->render('platzhalter', [
            'title' => $liste->titel(),
            'text' => $liste->beschreibung(),
        ], Area::App));
    });
};
