<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\VersionsAnlass;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;
use App\Repository\VersionKonfliktException;
use App\View\Area;
use App\View\FlashArt;

/**
 * The family of a member (docs/spec/07-mitglieder.md section 3, issue #20):
 * choose a family, create one, change the role, leave. Every change is a
 * normal save of the member (a new version, 07 section 7); a family that
 * loses its last person is deleted by MitgliedRepository. Both roles may do
 * this (07 section 9). Answers with a redirect to the member and a message.
 */
final readonly class FamilieController
{
    public function __construct(
        private Dienste $dienste,
        private MitgliedRepository $mitglieder,
        private FamilieRepository $familien,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d,
            new MitgliedRepository($d->pdo()),
            new FamilieRepository($d->pdo()),
        ));
    }

    public static function adresse(int $mitgliedId, string $aktion = ''): string
    {
        return MitgliedController::PFAD_LISTE . '/' . $mitgliedId . '/familie' . ($aktion === '' ? '' : '/' . $aktion);
    }

    /**
     * "Familie wählen": search by name; the matches to join, and a button
     * to create a new family with a proposed name.
     *
     * @param array<string, string> $params
     */
    public function waehlen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        $suche = $r->feld('q');

        return Response::html($this->dienste->view->render('mitglied/familie-waehlen', [
            'title' => 'Familie wählen',
            'mitglied' => $mitglied,
            'suche' => $suche,
            'treffer' => $this->familien->suche($suche),
            'vorschlag' => 'Familie ' . $mitglied->nachname,
            'rollen' => FamilienRolle::cases(),
        ], Area::App));
    }

    /** @param array<string, string> $params */
    public function beitreten(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        $familie = $this->familien->finde((int) $r->feld('familie_id'));
        if ($familie === null) {
            return $this->zurueck($mitglied, 'Diese Familie gibt es nicht mehr. Bitte neu suchen.', FlashArt::Warnung);
        }

        return $this->setzen($mitglied, $familie->id, $this->leseRolle($r), sprintf('Jetzt in der %s.', $familie->name));
    }

    /** @param array<string, string> $params */
    public function neu(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        $name = trim($r->feld('name'));
        if ($name === '') {
            $name = 'Familie ' . $mitglied->nachname;
        }

        $familie = $this->familien->anlegen(mb_substr($name, 0, 200));
        $antwort = $this->setzen($mitglied, $familie->id, $this->leseRolle($r), sprintf('Familie „%s" angelegt.', $familie->name));
        // The save did not happen (somebody else was faster): no empty family stays behind.
        $this->familien->loeschenWennLeer($familie->id);

        return $antwort;
    }

    private function leseRolle(Request $r): FamilienRolle
    {
        return FamilienRolle::tryFrom($r->feld('rolle')) ?? FamilienRolle::Elternteil;
    }

    /** @param array<string, string> $params */
    public function rolleAendern(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        if ($mitglied->familieId === null) {
            return $this->zurueck($mitglied, 'Das Mitglied ist in keiner Familie.', FlashArt::Warnung);
        }

        return $this->setzen($mitglied, $mitglied->familieId, $this->leseRolle($r), 'Rolle geändert.');
    }

    /** @param array<string, string> $params */
    public function umbenennen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        $name = trim($r->feld('name'));
        if ($mitglied->familieId === null || $name === '') {
            return $this->zurueck($mitglied, 'Bitte einen Namen für die Familie eintragen.', FlashArt::Warnung);
        }
        $this->familien->umbenennen($mitglied->familieId, mb_substr($name, 0, 200));

        return $this->zurueck($mitglied, 'Familie umbenannt.');
    }

    /** @param array<string, string> $params */
    public function verlassen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }

        return $this->setzen($mitglied, null, null, 'Das Mitglied ist nicht mehr in der Familie.');
    }

    private function setzen(Mitglied $mitglied, ?int $familieId, ?FamilienRolle $rolle, string $meldung): Response
    {
        try {
            $ergebnis = $this->mitglieder->speichern(
                $mitglied->mit(['familieId' => $familieId, 'familieRolle' => $rolle]),
                VersionsAnlass::geaendert(),
                $this->dienste->session->userId(),
            );
        } catch (VersionKonfliktException) {
            return $this->zurueck($mitglied, 'Jemand anderes hat dieses Mitglied gerade gespeichert. Bitte noch einmal versuchen.', FlashArt::Warnung);
        }

        return $this->zurueck($ergebnis->mitglied, $meldung);
    }

    private function zurueck(Mitglied $mitglied, string $meldung, FlashArt $art = FlashArt::Ok): Response
    {
        $this->dienste->session->flash($meldung, $art);

        return Response::redirect(MitgliedController::adresse((int) $mitglied->id, 'familie'));
    }

    private function gibtEsNicht(): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Mitglied nicht gefunden',
            'meldung' => 'Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es inzwischen gelöscht.',
            'startseite' => MitgliedController::PFAD_LISTE,
        ], Area::App), 404);
    }
}
