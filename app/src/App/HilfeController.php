<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\Konto;
use App\Http\Response;
use App\Service\Hilfe\Anleitungen;
use App\View\Area;
use App\View\Navigation;

/**
 * The help (docs/spec/12-hilfe.md): the overview and one guide. Read only,
 * no member data. Both routes are open to every logged-in account; which
 * guide a role sees is decided here, because the right changes from guide
 * to guide (12 section 1).
 */
final readonly class HilfeController
{
    public function __construct(private Dienste $dienste)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public static function adresse(string $slug): string
    {
        return Navigation::HILFE . '/' . $slug;
    }

    public function uebersicht(): Response
    {
        $konto = $this->dienste->view->konto();
        if ($konto === null) {
            return Response::redirect('/anmelden'); // the guard let it through, so this cannot happen
        }

        return Response::html($this->dienste->view->render('hilfe/uebersicht', [
            'title' => 'Hilfe',
            'anleitungen' => Anleitungen::aus($this->dienste)->fuer($konto->rolle),
        ], Area::App));
    }

    public function anleitung(string $slug): Response
    {
        $konto = $this->dienste->view->konto();
        if ($konto === null) {
            return Response::redirect('/anmelden');
        }

        $hilfe = Anleitungen::aus($this->dienste);
        $anleitung = $hilfe->finde($slug, $konto->rolle);
        if ($anleitung === null) {
            // Unknown and not allowed look the same: the overview does not
            // list it either.
            return $this->nichtGefunden();
        }

        $bilder = [];
        foreach ($anleitung->schritte as $schritt) {
            $bilder[$schritt->nummer] = $hilfe->bild($anleitung, $schritt, $konto->barrierefrei);
        }

        return Response::html($this->dienste->view->render('hilfe/anleitung', [
            'title' => $anleitung->titel,
            'anleitung' => $anleitung,
            'bilder' => $bilder,
            'naechste' => $hilfe->naechste($anleitung, $konto->rolle),
        ], Area::App));
    }

    private function nichtGefunden(): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Anleitung nicht gefunden',
            'meldung' => 'Diese Anleitung gibt es nicht. Alle Anleitungen finden Sie unter „Hilfe“ im Benutzermenü.',
            'startseite' => Navigation::HILFE,
        ], Area::App), 404);
    }
}
