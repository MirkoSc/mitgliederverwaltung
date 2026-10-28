<?php

declare(strict_types=1);

use App\Http\Entwicklungskonten;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\View\Area;

/**
 * Placeholder of the login (issue #1) - issue #6 replaces this file with
 * the real login: e-mail, password, second factor (docs/spec/02-sicherheit.md
 * section 3).
 *
 * Until then the login page offers the two development accounts
 * (App\Http\Entwicklungskonten), and only where those are switched on:
 * `debug` in config.php and a development version. Everywhere else it just
 * says that the login is not there yet.
 */
return static function (Routen $routen): void {
    $oeffentlich = Zugriff::oeffentlich();

    $entwicklung = static function () use ($routen): ?Entwicklungskonten {
        $konten = $routen->dienste()->kontoquelle();

        return $konten instanceof Entwicklungskonten && $konten->aktiv() ? $konten : null;
    };

    // Public - the way in. It has a session, because its form needs a CSRF
    // token.
    $routen->get('/anmelden', $oeffentlich, static function (Request $r) use ($routen, $entwicklung): Response {
        $dienste = $routen->dienste();
        $dienste->session->start();

        return Response::html($dienste->view->render('anmelden', [
            'title' => 'Anmelden',
            'entwicklung' => $entwicklung() !== null,
            'abgelaufen' => $r->feld('abgelaufen') === '1',
            'csrf' => $dienste->session->csrfToken(),
        ]));
    });

    // Public, development only: log in as one of the two made-up accounts.
    $routen->post('/anmelden/entwicklung', $oeffentlich, static function (Request $r) use ($routen, $entwicklung): Response {
        $konten = $entwicklung();
        $id = match ($r->feld('konto')) {
            'admin' => Entwicklungskonten::ADMIN,
            'vorstand' => Entwicklungskonten::VORSTAND,
            default => null,
        };
        if ($konten === null || $id === null || $konten->konto($id) === null) {
            return Response::html($routen->dienste()->view->render('fehler', [
                'title' => 'Seite nicht gefunden',
                'meldung' => 'Diese Adresse gibt es nicht. Prüfen Sie den Link oder gehen Sie zur Startseite.',
            ], Area::Oeffentlich), 404);
        }

        $routen->dienste()->session->login($id);

        return Response::redirect('/app');
    });

    // Public: logging out needs no login, and it is a POST with a CSRF
    // token - a GET that ends a session can be triggered by any image tag.
    $routen->post('/abmelden', $oeffentlich, static function (Request $r) use ($routen): Response {
        $routen->dienste()->session->destroy();

        return Response::redirect('/anmelden');
    });
};
