<?php

declare(strict_types=1);

use App\Domain\Permission;
use App\Domain\Rechtstext;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routen;
use App\Http\Zugriff;
use App\Service\Rechtstexte\Rechtstexte;
use App\Support\Markdown;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * Impressum and Datenschutz (docs/spec/02-sicherheit.md section 6, E-F3):
 * the public pages, and the admin's form in the settings section. Reading is
 * public, writing needs admin.einstellungen.
 */
return static function (Routen $routen): void {
    foreach (Rechtstext::cases() as $art) {
        $routen->get($art->pfad(), Zugriff::oeffentlich(), static function (Request $r) use ($routen, $art): Response {
            $dienste = $routen->dienste();
            $text = Rechtstexte::aus($dienste)->text($art);

            return Response::html($dienste->view->render('rechtstext', [
                'title' => $art->titel(),
                'html' => Markdown::html($text),
                'leer' => trim($text) === '',
            ], Area::Oeffentlich));
        });

        $routen->post('/admin/rechtstexte/' . $art->value, Zugriff::recht(Permission::AdminEinstellungen), static function (Request $r) use ($routen, $art): Response {
            $dienste = $routen->dienste();
            $text = $r->feld('text');

            // Too long is refused with a message, never cut off (Rechtstexte::MAX_ZEICHEN).
            if (Rechtstexte::istZuLang($text)) {
                $dienste->session->flash(
                    'Der Text ist zu lang. Höchstens ' . Rechtstexte::MAX_ZEICHEN . ' Zeichen sind möglich.',
                    FlashArt::Fehler,
                );

                return Response::redirect(Navigation::EINSTELLUNGEN . '#rechtstexte');
            }

            Rechtstexte::aus($dienste)->speichern($art, $text);
            $dienste->session->flash($art->titel() . ' gespeichert.');

            return Response::redirect(Navigation::EINSTELLUNGEN . '#rechtstexte');
        });
    }
};
