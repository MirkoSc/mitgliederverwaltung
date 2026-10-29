<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\BeitragNichtAenderbar;
use App\Domain\JahresbeitragStatus;
use App\Domain\JahresbeitragZeile;
use App\Domain\Zahlweise;
use App\Http\Request;
use App\Http\Response;
use App\Repository\BenutzerRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Service\Beitraege\Jahresbeitraege;
use App\Support\Datum;
use App\Support\Geld;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * The page "Beiträge" (docs/spec/08-beitraege.md section 2, 06 section 2):
 * the year's annual fees - create them, the open ones with "bezahlt" -
 * and the page of one fee with everything that can happen to it. Admin
 * only; every route declares BeitraegeVerwalten (app/routes/beitraege.php).
 *
 * Every action answers with a redirect and a message. Where to: the
 * `zurueck` field, but only an address of this application's fees or
 * members (no open redirect), else the page of the fee.
 */
final readonly class BeitraegeController
{
    public const int PRO_SEITE = 50;

    /** Filter of the list; the default shows what still needs doing. */
    private const array FILTER = ['offen' => 'offene', 'im_einzug' => 'im Einzug', 'bezahlt' => 'bezahlte', 'alle' => 'alle'];

    public function __construct(private Dienste $dienste, private Jahresbeitraege $service, private JahresbeitragRepository $beitraege)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d,
            Jahresbeitraege::mit($d->pdo()),
            new JahresbeitragRepository($d->pdo()),
        ));
    }

    /** The page of one fee, `?zurueck=` keeps where it was opened from. */
    public static function adresse(int $id, ?string $zurueck = null): string
    {
        return Navigation::BEITRAEGE . '/' . $id . ($zurueck === null ? '' : '?' . http_build_query(['zurueck' => $zurueck]));
    }

    /**
     * @param array<string, string> $abfrage
     */
    public static function seitenAdresse(int $jahr, array $abfrage = []): string
    {
        return Navigation::BEITRAEGE . '?' . http_build_query(['jahr' => (string) $jahr, ...$abfrage]);
    }

    public function seite(Request $r): Response
    {
        $heute = self::heute();
        $jahr = $this->jahr($r->feld('jahr'), $heute);
        $filter = array_key_exists($r->feld('status'), self::FILTER) ? $r->feld('status') : 'offen';

        $alle = $this->beitraege->zeilenImJahr($jahr);
        $summen = [];
        foreach (JahresbeitragStatus::cases() as $status) {
            $summen[$status->value] = ['anzahl' => 0, 'summe' => 0];
        }
        foreach ($alle as $zeile) {
            $summen[$zeile->beitrag->status->value]['anzahl']++;
            $summen[$zeile->beitrag->status->value]['summe'] += $zeile->beitrag->betragCent;
        }

        $gefiltert = $filter === 'alle'
            ? $alle
            : array_values(array_filter($alle, static fn(JahresbeitragZeile $z): bool => $z->beitrag->status->value === $filter));
        $seiten = max(1, (int) ceil(count($gefiltert) / self::PRO_SEITE));
        $seite = min($seiten, max(1, (int) $r->feld('seite')));

        $abfrage = $filter === 'offen' ? [] : ['status' => $filter];

        return Response::html($this->dienste->view->render('beitraege/seite', [
            'title' => 'Beiträge',
            'jahr' => $jahr,
            'vorjahr' => $jahr > Jahresbeitraege::ERSTES_JAHR ? $jahr - 1 : null,
            'folgejahr' => $jahr < Jahresbeitraege::letztesJahr($heute) ? $jahr + 1 : null,
            'filter' => $filter,
            'filterWerte' => self::FILTER,
            'summen' => $summen,
            'gesamt' => count($alle),
            'anzahl' => count($gefiltert),
            'zeilen' => array_slice($gefiltert, ($seite - 1) * self::PRO_SEITE, self::PRO_SEITE),
            'seite' => $seite,
            'seiten' => $seiten,
            'hier' => self::seitenAdresse($jahr, [...$abfrage, ...($seite > 1 ? ['seite' => (string) $seite] : [])]),
            'link' => static fn(array $aenderungen): string => self::seitenAdresse($jahr, array_filter(
                [...$abfrage, ...$aenderungen],
                static fn(?string $wert): bool => $wert !== null,
            )),
            'zahlweisen' => Zahlweise::cases(),
        ], Area::App));
    }

    public function erzeugen(Request $r): Response
    {
        $jahr = $this->jahr($r->feld('jahr'), self::heute(), streng: true);
        if ($jahr === null) {
            $this->dienste->session->flash('Für dieses Jahr lassen sich keine Beiträge erzeugen.', FlashArt::Warnung);

            return Response::redirect(Navigation::BEITRAEGE);
        }

        $ergebnis = $this->service->erzeugen($jahr, $this->dienste->session->userId());
        $this->dienste->session->flash($ergebnis->meldung());

        return Response::redirect(self::seitenAdresse($jahr));
    }

    /** @param array<string, string> $params */
    public function beitrag(Request $r, array $params): Response
    {
        $beitrag = $this->beitraege->finde((int) $params['id']);
        $mitglied = $beitrag === null ? null : new MitgliedRepository($this->dienste->pdo())->finde($beitrag->mitgliedId);
        if ($beitrag === null || $mitglied === null) {
            return $this->nichtGefunden();
        }

        $zurueck = self::sichereAdresse($r->feld('zurueck')) ?? self::seitenAdresse($beitrag->jahr);
        $benutzer = new BenutzerRepository($this->dienste->pdo());
        $namen = [];
        foreach ($beitrag->vermerke as $vermerk) {
            if ($vermerk->von !== null && !array_key_exists($vermerk->von, $namen)) {
                $namen[$vermerk->von] = $benutzer->findById($vermerk->von)?->name;
            }
        }

        return Response::html($this->dienste->view->render('beitraege/beitrag', [
            'title' => 'Jahresbeitrag ' . $beitrag->jahr,
            'beitrag' => $beitrag,
            'mitglied' => $mitglied,
            'namen' => $namen,
            'zurueck' => $zurueck,
            'zurueckText' => str_starts_with($zurueck, MitgliedController::PFAD_LISTE) ? 'Zurück zum Mitglied' : 'Zurück zu den Beiträgen',
            'hier' => self::adresse((int) $beitrag->id, $zurueck),
            'zahlweisen' => Zahlweise::cases(),
            'heute' => self::heute(),
        ], Area::App));
    }

    /** @param array<string, string> $params */
    public function betrag(Request $r, array $params): Response
    {
        $cent = Geld::ausEingabe($r->feld('betrag'));

        return $this->ausfuehren($r, (int) $params['id'], fn(int $id, ?int $von) => $cent === null
            ? throw new BeitragNichtAenderbar('Der Betrag ist keine Zahl. Bitte so eingeben: 42,50')
            : $this->service->betragAendern($id, $cent, $r->feld('grund'), $von), 'Betrag geändert.');
    }

    /** @param array<string, string> $params */
    public function bezahlt(Request $r, array $params): Response
    {
        $zahlweise = Zahlweise::tryFrom($r->feld('zahlweise'));
        $am = $r->feld('bezahlt_am') === '' ? self::heute() : Datum::ausEingabe($r->feld('bezahlt_am'));

        return $this->ausfuehren($r, (int) $params['id'], fn(int $id, ?int $von) => match (true) {
            $zahlweise === null => throw new BeitragNichtAenderbar('Bitte wählen Sie, wie bezahlt wurde.'),
            $am === null => throw new BeitragNichtAenderbar('Das Datum ist ungültig. Bitte so eingeben: 31.12.2026'),
            default => $this->service->bezahltVonHand($id, $zahlweise, $am, $r->feld('vermerk'), $von),
        }, 'Als bezahlt vermerkt.');
    }

    /** @param array<string, string> $params */
    public function ruecklastschrift(Request $r, array $params): Response
    {
        return $this->ausfuehren(
            $r,
            (int) $params['id'],
            fn(int $id, ?int $von) => $this->service->ruecklastschrift($id, $r->feld('vermerk'), $von),
            'Der Beitrag ist wieder offen.',
        );
    }

    /** @param array<string, string> $params */
    public function vermerk(Request $r, array $params): Response
    {
        return $this->ausfuehren(
            $r,
            (int) $params['id'],
            fn(int $id, ?int $von) => $this->service->vermerken($id, $r->feld('vermerk'), $von),
            'Vermerk gespeichert.',
        );
    }

    /**
     * A local address of the fees or members pages - the only places an
     * action returns to. Null for anything else.
     */
    public static function sichereAdresse(string $adresse): ?string
    {
        return preg_match('#^/app/(beitraege(/[0-9]+)?|mitglieder)(\?[A-Za-z0-9_=&%.\-]*)?$#D', $adresse) === 1 ? $adresse : null;
    }

    /**
     * @param \Closure(int, ?int): mixed $aktion
     */
    private function ausfuehren(Request $r, int $id, \Closure $aktion, string $erfolg): Response
    {
        $ziel = self::sichereAdresse($r->feld('zurueck')) ?? self::adresse($id);
        try {
            $aktion($id, $this->dienste->session->userId());
            $this->dienste->session->flash($erfolg);
        } catch (\OutOfBoundsException) {
            return $this->nichtGefunden();
        } catch (BeitragNichtAenderbar $e) {
            $this->dienste->session->flash($e->getMessage(), FlashArt::Warnung);
        }

        return Response::redirect($ziel);
    }

    private function nichtGefunden(): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Beitrag nicht gefunden',
            'meldung' => 'Diesen Jahresbeitrag gibt es nicht (mehr).',
            'startseite' => Navigation::BEITRAEGE,
        ], Area::App), 404);
    }

    /**
     * The year asked for; without one (or a nonsense one) the current
     * year. $streng: null instead of the current year - for creating.
     *
     * @return ($streng is true ? int|null : int)
     */
    private function jahr(string $eingabe, \DateTimeImmutable $heute, bool $streng = false): ?int
    {
        $jahr = preg_match('/^[0-9]{4}$/D', $eingabe) === 1 ? (int) $eingabe : null;
        if ($jahr !== null && $jahr >= Jahresbeitraege::ERSTES_JAHR && $jahr <= Jahresbeitraege::letztesJahr($heute)) {
            return $jahr;
        }

        return $streng ? null : (int) $heute->format('Y');
    }

    private static function heute(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE));
    }
}
