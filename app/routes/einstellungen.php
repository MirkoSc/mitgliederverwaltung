<?php

declare(strict_types=1);

use App\Admin\Einstellungen\Abschnitte;
use App\Domain\Permission;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Area;
use App\View\Navigation;

/**
 * The settings page (docs/spec/06-oberflaeche.md section 2): one page, one
 * section per topic. The sections are found by App\Admin\Einstellungen\
 * Abschnitte; each issue adds its own and registers the routes its forms
 * post to in its own route file.
 *
 * The page needs admin.verein (Admin and Verwaltung); each section shows
 * only for whoever holds its own right, and each route a section posts to
 * checks its own - so "Verwaltung" sees the business sections (club data,
 * departments, fee types, functions, birthdays, honour levels) and none of
 * the technical ones (mail, legal texts, users, import, system, audit log;
 * docs/spec/02-sicherheit.md section 6).
 */
return static function (Routen $routen): void {
    $routen->get(Navigation::EINSTELLUNGEN, Zugriff::recht(Permission::AdminVerein), static function (Request $r) use ($routen): Response {
        $dienste = $routen->dienste();
        $abschnitte = [];
        foreach (Abschnitte::registry()->alle($dienste, $dienste->view->konto()) as $abschnitt) {
            $abschnitte[] = [
                'schluessel' => $abschnitt->schluessel(),
                'titel' => $abschnitt->titel(),
                'inhalt' => $dienste->view->fragment($abschnitt->template(), $abschnitt->daten()),
            ];
        }

        return Response::html($dienste->view->render('admin/einstellungen', [
            'title' => 'Einstellungen',
            'abschnitte' => $abschnitte,
            'scripts' => ['/js/inhaltsverzeichnis.js'],
        ], Area::Admin));
    });
};
