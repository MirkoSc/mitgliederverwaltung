<?php

declare(strict_types=1);

namespace App\App;

use App\App\Mitglied\Einbettung;
use App\App\Mitglied\MitgliedAnsicht;
use App\Dienste;
use App\Domain\Familie;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\Permission;
use App\Domain\Wohnanschrift;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FamilieRepository;
use App\Repository\FamilienListeRepository;
use App\Repository\MitgliedRepository;
use App\Repository\VersionKonfliktException;
use App\Service\Mitglied\FamilienPflege;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * The page "Familien" (issue #92, docs/spec/07-mitglieder.md section 3):
 * the list of all families on the left, the opened family on the right -
 * its persons with their roles, the address check and the ways to change
 * it. A person of the family opens in place of the family, as the member
 * building block (MitgliedAnsicht, 06 section 5) with "Zurück zur
 * Familie". On a phone it is one view after the other (list, family,
 * member), in the Barrierefrei-Modus one below the other.
 *
 * Like the members page: one address, two verbs. Family and member are ids
 * in the URL (shareable, the back button works); a search text - a family
 * name is a surname - only ever travels in the POST body (CLAUDE.md
 * section 4). htmx asks for a part by the id it swaps (HX-Target).
 *
 * The changes post to routes of their own and answer with a redirect to
 * the family and a message. Every change of a person is a normal save of
 * the member (FamilienPflege, a new version each).
 */
final readonly class FamilienController
{
    public const string PFAD = Navigation::FAMILIEN;

    private const string ZIEL_BEREICH = 'familien-bereich';
    private const string ZIEL_ERGEBNIS = 'familien-ergebnis';
    private const string ZIEL_ANSCHRIFT = 'familie-anschrift';
    private const string ZIEL_TREFFER = 'familie-treffer';

    /** Matches shown when adding a person - the search narrows it down. */
    private const int TREFFER = 20;

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

    /** The page with a family opened (null: without). */
    public static function adresse(?int $familieId = null): string
    {
        return self::PFAD . ($familieId === null ? '' : '?' . http_build_query(['familie' => (string) $familieId]));
    }

    /** The address of a change of a family, e.g. /app/familien/5/rolle. */
    public static function aktion(int $familieId, string $aktion): string
    {
        return self::PFAD . '/' . $familieId . '/' . $aktion;
    }

    public function seite(Request $r): Response
    {
        $view = $this->dienste->view;
        $daten = [...$r->query, ...$r->post]; // the body is the more recent state
        $text = self::ausDemBody($r, 'text');

        // The member says which family it is in: after it joined another
        // one (tab "Familie"), the page follows it there.
        $mitgliedId = MitgliedAnsicht::id($daten['mitglied'] ?? null);
        $mitglied = $mitgliedId === null ? null : $this->mitglieder->finde($mitgliedId);
        $familieId = $mitglied !== null ? $mitglied->familieId : MitgliedAnsicht::id($daten['familie'] ?? null);
        $familie = $familieId === null ? null : $this->familien->finde($familieId);

        $liste = new Einbettung(self::PFAD)->mitHtmx(self::ZIEL_BEREICH, '#familien-form');
        $hier = new Einbettung(self::PFAD, $familie === null ? [] : ['familie' => (string) $familie->id])
            ->mitHtmx(self::ZIEL_BEREICH, '#familien-form')
            ->mitZurueck($familie === null ? 'Zurück zur Liste' : 'Zurück zur Familie');
        $ansicht = MitgliedAnsicht::aus($this->dienste)->oeffnen($r, $hier);

        $konto = $view->konto();
        $detail = $familie !== null && !$ansicht->istOffen() ? $this->familienDaten($r, $familie, $daten, $liste, $hier) : null;
        $daten = [
            'title' => 'Familien',
            'text' => $text,
            'zeilen' => new FamilienListeRepository($this->dienste->pdo())->liste($text),
            'liste' => $liste,
            'familie' => $familie,
            'familieUnbekannt' => $familieId !== null && $familie === null && $mitgliedId === null,
            'ansicht' => $ansicht,
            'offen' => $detail !== null || $ansicht->istOffen(),
            'detail' => $detail,
            'detailHtml' => $detail !== null ? $view->fragment('familien/familie', $detail) : $ansicht->html,
            'formUrl' => $hier->adresse($ansicht->id(), $ansicht->reiter),
            'nurErgebnis' => false,
            'darfPflegen' => $konto?->darf(Permission::MitgliederBearbeiten) === true,
            'scripts' => [...MitgliedAnsicht::SKRIPTE, '/js/familien.js'],
            'styles' => MitgliedAnsicht::STILE,
        ];

        $htmx = $r->istHtmx() && $r->header('hx-history-restore-request') !== 'true';

        return match (true) {
            $htmx && $r->header('hx-target') === self::ZIEL_ERGEBNIS => Response::html($view->fragment('familien/ergebnis', [...$daten, 'nurErgebnis' => true])),
            $htmx && $r->header('hx-target') === self::ZIEL_ANSCHRIFT && $detail !== null => Response::html($view->fragment('familien/anschrift', $detail)),
            $htmx && $r->header('hx-target') === self::ZIEL_TREFFER && $detail !== null => Response::html($view->fragment('familien/treffer', $detail)),
            $htmx && $r->header('hx-target') === self::ZIEL_BEREICH => Response::html($view->fragment('familien/bereich', $daten)),
            default => Response::html($view->render('familien/seite', $daten, Area::App)),
        };
    }

    /**
     * What the opened family shows: its persons, whose address differs
     * from the chosen source, and the matches of the search to add one.
     *
     * @param array<array-key, mixed> $anfrage query and body
     * @return array<string, mixed>
     */
    private function familienDaten(Request $r, Familie $familie, array $anfrage, Einbettung $liste, Einbettung $hier): array
    {
        $personen = $this->mitglieder->derFamilie($familie->id);

        // The source of "Anschrift übernehmen": as chosen, else the payer, else the first person.
        $quelle = null;
        $quelleId = MitgliedAnsicht::id($anfrage['quelle'] ?? null);
        foreach ($personen as $person) {
            if ($person->id === $quelleId) {
                $quelle = $person;
            }
        }
        foreach ($personen as $person) {
            $quelle ??= $person->familieRolle === FamilienRolle::Zahler ? $person : null;
        }
        $quelle ??= $personen[0] ?? null;

        $anschrift = $quelle === null ? null : Wohnanschrift::von($quelle);
        $abweichend = [];
        foreach ($personen as $person) {
            if ($anschrift !== null && !Wohnanschrift::von($person)->gleich($anschrift)) {
                $abweichend[] = (int) $person->id;
            }
        }

        $suche = self::ausDemBody($r, 'suche');
        $treffer = [];
        if ($suche !== '') {
            $ids = array_map(static fn(Mitglied $p): ?int => $p->id, $personen);
            $treffer = array_values(array_filter(
                $this->mitglieder->suche($suche, limit: self::TREFFER + count($ids)),
                static fn(Mitglied $m): bool => !in_array($m->id, $ids, true),
            ));
            $treffer = array_slice($treffer, 0, self::TREFFER);
        }

        $andereFamilien = [];
        foreach ($treffer as $m) {
            if ($m->familieId !== null && !isset($andereFamilien[$m->familieId])) {
                $andereFamilien[$m->familieId] = $this->familien->finde($m->familieId)?->name ?? '';
            }
        }

        $zahler = array_values(array_filter($personen, static fn(Mitglied $p): bool => $p->familieRolle === FamilienRolle::Zahler));

        return [
            'familie' => $familie,
            'personen' => $personen,
            'zahler' => $zahler,
            'quelle' => $quelle,
            'abweichend' => $abweichend,
            'anschriftenAbweichend' => self::weichenAb($personen),
            'suche' => $suche,
            'treffer' => $treffer,
            'andereFamilien' => $andereFamilien,
            'rollen' => FamilienRolle::cases(),
            'liste' => $liste,
            'hier' => $hier,
            'darfPflegen' => $this->dienste->view->konto()?->darf(Permission::MitgliederBearbeiten) === true,
            'fokus' => self::fokus($r, $anfrage, $this->dienste->session->hatFlash()),
        ];
    }

    /**
     * @param list<Mitglied> $personen
     */
    private static function weichenAb(array $personen): bool
    {
        $erste = isset($personen[0]) ? Wohnanschrift::von($personen[0]) : null;
        foreach ($personen as $person) {
            if ($erste !== null && !Wohnanschrift::von($person)->gleich($erste)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the heading of the family takes the focus: a whole page that
     * shows it without a message waiting at the top, or an htmx answer to
     * a link that opened the family or led back to it.
     *
     * @param array<array-key, mixed> $anfrage
     */
    private static function fokus(Request $r, array $anfrage, bool $meldungWartet): bool
    {
        if ($r->header('hx-history-restore-request') === 'true') {
            return false;
        }
        if (!$r->istHtmx()) {
            return !$meldungWartet;
        }

        return in_array($anfrage['fokus'] ?? null, ['familie', 'zurueck'], true);
    }

    /** A text only from the body - never from the query, so it is never in a URL. */
    private static function ausDemBody(Request $r, string $name): string
    {
        $wert = $r->post[$name] ?? null;

        return is_string($wert) ? mb_substr(trim($wert), 0, 100) : '';
    }

    // ------------------------------------------------------------------
    // Changes (mitglieder.bearbeiten). Each answers with a redirect.

    public function neu(Request $r): Response
    {
        $name = trim($r->feld('name'));
        if ($name === '') {
            return $this->weiter(null, 'Bitte einen Namen für die neue Familie eintragen, z. B. „Familie Muster“.', FlashArt::Warnung);
        }
        $familie = $this->familien->anlegen(mb_substr($name, 0, 200));

        return $this->weiter($familie->id, 'Familie angelegt. Fügen Sie jetzt die Personen hinzu.');
    }

    /** @param array<string, string> $params */
    public function umbenennen(Request $r, array $params): Response
    {
        $familie = $this->familien->finde((int) $params['id']);
        if ($familie === null) {
            return $this->gibtEsNicht();
        }
        $name = trim($r->feld('name'));
        if ($name === '') {
            return $this->weiter($familie->id, 'Bitte einen Namen für die Familie eintragen.', FlashArt::Warnung);
        }
        $this->familien->umbenennen($familie->id, mb_substr($name, 0, 200));

        return $this->weiter($familie->id, 'Familie umbenannt.');
    }

    /** @param array<string, string> $params */
    public function loeschen(Request $r, array $params): Response
    {
        $familie = $this->familien->finde((int) $params['id']);
        if ($familie === null) {
            return $this->gibtEsNicht();
        }
        if (!$this->familien->loeschenWennLeer($familie->id)) {
            return $this->weiter($familie->id, 'In der Familie sind noch Personen. Nehmen Sie zuerst alle heraus – dann verschwindet sie von selbst.', FlashArt::Warnung);
        }

        return $this->weiter(null, 'Familie gelöscht.');
    }

    /** @param array<string, string> $params */
    public function hinzufuegen(Request $r, array $params): Response
    {
        $familie = $this->familien->finde((int) $params['id']);
        if ($familie === null) {
            return $this->gibtEsNicht();
        }
        $mitglied = $this->mitglieder->finde((int) $r->feld('mitglied_id'));
        if ($mitglied === null) {
            return $this->weiter($familie->id, 'Dieses Mitglied gibt es nicht mehr. Bitte neu suchen.', FlashArt::Warnung);
        }
        $vorher = $mitglied->familieId === null || $mitglied->familieId === $familie->id ? null : $this->familien->finde($mitglied->familieId);

        return $this->setzen($familie->id, $mitglied, $familie->id, self::leseRolle($r), 'Zur Familie hinzugefügt.'
            . ($vorher === null ? '' : ' Vorher war die Person in „' . $vorher->name . '“.'));
    }

    /** @param array<string, string> $params */
    public function rolle(Request $r, array $params): Response
    {
        [$familie, $person] = $this->familieUndPerson($r, $params);
        if ($familie === null) {
            return $this->gibtEsNicht();
        }
        if ($person === null) {
            return $this->keinePerson($familie);
        }

        return $this->setzen($familie->id, $person, $familie->id, self::leseRolle($r), 'Rolle geändert.');
    }

    /** @param array<string, string> $params */
    public function zahler(Request $r, array $params): Response
    {
        [$familie, $person] = $this->familieUndPerson($r, $params);
        if ($familie === null) {
            return $this->gibtEsNicht();
        }
        if ($person === null) {
            return $this->keinePerson($familie);
        }
        try {
            FamilienPflege::aus($this->dienste)->zahlerWechseln($familie->id, (int) $person->id);
        } catch (VersionKonfliktException) {
            return $this->konflikt($familie->id);
        }

        return $this->weiter($familie->id, $person->anzeigeName() . ' ist jetzt Zahler der Familie.');
    }

    /** @param array<string, string> $params */
    public function entfernen(Request $r, array $params): Response
    {
        [$familie, $person] = $this->familieUndPerson($r, $params);
        if ($familie === null) {
            return $this->gibtEsNicht();
        }
        if ($person === null) {
            return $this->keinePerson($familie);
        }
        try {
            FamilienPflege::aus($this->dienste)->setzen($person, null, null);
        } catch (VersionKonfliktException) {
            return $this->konflikt($familie->id);
        }
        if ($this->familien->finde($familie->id) === null) {
            return $this->weiter(null, 'Aus der Familie genommen. Die Familie hatte danach niemanden mehr und ist gelöscht.');
        }

        return $this->weiter($familie->id, 'Aus der Familie genommen.');
    }

    /** @param array<string, string> $params */
    public function anschrift(Request $r, array $params): Response
    {
        $familie = $this->familien->finde((int) $params['id']);
        if ($familie === null) {
            return $this->gibtEsNicht();
        }
        $gewaehlt = $r->post['mitglieder'] ?? [];
        $ids = [];
        foreach (is_array($gewaehlt) ? $gewaehlt : [] as $wert) {
            $id = MitgliedAnsicht::id($wert);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $this->weiter($familie->id, 'Niemand ist angehakt – es wurde nichts geändert.', FlashArt::Info);
        }

        try {
            $ergebnis = FamilienPflege::aus($this->dienste)->anschriftUebernehmen($familie->id, (int) $r->feld('quelle'), $ids, $r->ip);
        } catch (\InvalidArgumentException) {
            return $this->weiter($familie->id, 'Die gewählte Anschrift gehört zu niemandem in dieser Familie. Bitte neu wählen.', FlashArt::Warnung);
        }

        $anzahl = count($ergebnis->geaendert);
        $text = match ($anzahl) {
            0 => 'Nichts geändert – die angehakten Personen haben diese Anschrift schon.',
            1 => 'Anschrift bei 1 Person übernommen.',
            default => sprintf('Anschrift bei %d Personen übernommen.', $anzahl),
        };
        if ($ergebnis->konflikte > 0) {
            $text .= sprintf(' %d %s gerade von jemand anderem gespeichert und blieb unverändert. Bitte noch einmal versuchen.', $ergebnis->konflikte, $ergebnis->konflikte === 1 ? 'Person wurde' : 'Personen wurden');
        }

        return $this->weiter($familie->id, $text, $ergebnis->konflikte > 0 ? FlashArt::Warnung : FlashArt::Ok);
    }

    /**
     * The family of the route and the person of the field `mitglied_id` -
     * the person only when it belongs to that family.
     *
     * @param array<string, string> $params
     * @return array{0: Familie|null, 1: Mitglied|null}
     */
    private function familieUndPerson(Request $r, array $params): array
    {
        $familie = $this->familien->finde((int) $params['id']);
        $person = $familie === null ? null : $this->mitglieder->finde((int) $r->feld('mitglied_id'));

        return [$familie, $person?->familieId === $familie?->id ? $person : null];
    }

    private function setzen(int $zurueck, Mitglied $mitglied, ?int $familieId, ?FamilienRolle $rolle, string $meldung): Response
    {
        try {
            FamilienPflege::aus($this->dienste)->setzen($mitglied, $familieId, $rolle);
        } catch (VersionKonfliktException) {
            return $this->konflikt($zurueck);
        }

        return $this->weiter($zurueck, $meldung);
    }

    private static function leseRolle(Request $r): FamilienRolle
    {
        return FamilienRolle::tryFrom($r->feld('rolle')) ?? FamilienRolle::Elternteil;
    }

    private function weiter(?int $familieId, string $meldung, FlashArt $art = FlashArt::Ok): Response
    {
        $this->dienste->session->flash($meldung, $art);

        return Response::redirect(self::adresse($familieId));
    }

    private function konflikt(int $familieId): Response
    {
        return $this->weiter($familieId, 'Jemand anderes hat diese Person gerade gespeichert. Bitte noch einmal versuchen.', FlashArt::Warnung);
    }

    private function keinePerson(Familie $familie): Response
    {
        return $this->weiter($familie->id, 'Diese Person ist nicht (mehr) in der Familie. Bitte die Seite neu laden.', FlashArt::Warnung);
    }

    private function gibtEsNicht(): Response
    {
        return $this->weiter(null, 'Diese Familie gibt es nicht mehr. Möglicherweise wurde sie inzwischen gelöscht.', FlashArt::Warnung);
    }
}
