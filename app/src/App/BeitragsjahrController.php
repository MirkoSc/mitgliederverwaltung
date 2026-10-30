<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\BeitragNichtAenderbar;
use App\Domain\Jahresbeitrag;
use App\Domain\Zahlweise;
use App\Http\Request;
use App\Http\Response;
use App\Service\Beitraege\BeitragsjahrLoeschen;
use App\Service\Beitraege\Jahresbeitraege;
use App\Support\Datum;
use App\Support\Geld;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * Actions on the page "Beiträge" that take more than one fee
 * (docs/spec/08-beitraege.md section 2a, #90): delete the fees of a whole
 * year, and mark several open fees as paid at once. Admin only; every
 * route declares BeitraegeVerwalten (app/routes/beitraege-jahr.php).
 */
final readonly class BeitragsjahrController
{
    public const string PFAD_LOESCHEN = Navigation::BEITRAEGE . '/jahr-loeschen';

    public const string PFAD_BEZAHLT = Navigation::BEITRAEGE . '/bezahlt';

    /** Hidden field after the checkboxes: without it the list was cut on the way (max_input_vars). */
    public const string VOLLSTAENDIG = 'auswahl_vollstaendig';

    public function __construct(private Dienste $dienste, private BeitragsjahrLoeschen $loeschen, private Jahresbeitraege $beitraege)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return new self($dienste, BeitragsjahrLoeschen::aus($dienste), Jahresbeitraege::mit($dienste->pdo()));
    }

    public static function loeschAdresse(int $jahr): string
    {
        return self::PFAD_LOESCHEN . '?' . http_build_query(['jahr' => (string) $jahr]);
    }

    /** The question before deleting (06 section 4, "Rückfragen"): what goes, in whole sentences. */
    public function loeschenFragen(Request $r): Response
    {
        $jahr = self::jahr($r->feld('jahr'));
        if ($jahr === null) {
            return Response::redirect(Navigation::BEITRAEGE);
        }

        return Response::html($this->dienste->view->render('beitraege/jahr-loeschen', [
            'title' => 'Beiträge ' . $jahr . ' löschen',
            'umfang' => $this->loeschen->umfang($jahr),
            'aktion' => self::PFAD_LOESCHEN,
            'zurueck' => BeitraegeController::seitenAdresse($jahr),
            'wort' => BeitragsjahrLoeschen::BESTAETIGUNG,
        ], Area::App));
    }

    public function loeschen(Request $r): Response
    {
        $jahr = self::jahr($r->feld('jahr'));
        if ($jahr === null) {
            return Response::redirect(Navigation::BEITRAEGE);
        }

        try {
            $umfang = $this->loeschen->loeschen($jahr, $r->feld('bestaetigung'), $this->dienste->session->userId(), $r->ip);
        } catch (BeitragNichtAenderbar $e) {
            $this->dienste->session->flash($e->getMessage(), FlashArt::Warnung);

            return Response::redirect(self::loeschAdresse($jahr));
        }
        $this->dienste->session->flash($umfang->meldung());

        return Response::redirect(BeitraegeController::seitenAdresse($jahr));
    }

    /**
     * "Ausgewählte als bezahlt markieren": the ticked fees, with the same
     * details as one fee (how, when, note). All or nothing.
     */
    public function bezahlt(Request $r): Response
    {
        $session = $this->dienste->session;
        $ziel = BeitraegeController::sichereAdresse($r->feld('zurueck')) ?? Navigation::BEITRAEGE;
        $auswahl = $r->post['beitrag'] ?? [];
        $ids = is_array($auswahl) ? array_values(array_map(intval(...), array_filter($auswahl, is_string(...)))) : [];
        $zahlweise = Zahlweise::tryFrom($r->feld('zahlweise'));
        $am = $r->feld('bezahlt_am') === '' ? self::heute() : Datum::ausEingabe($r->feld('bezahlt_am'));

        $warnung = match (true) {
            $r->feld(self::VOLLSTAENDIG) !== '1' => 'Die Auswahl kam nicht vollständig beim Server an, es wurde nichts geändert. Bitte versuchen Sie es erneut.',
            $ids === [] => 'Bitte wählen Sie mindestens einen Beitrag aus.',
            $zahlweise === null => 'Bitte wählen Sie, wie bezahlt wurde.',
            $am === null => 'Das Datum ist ungültig. Bitte so eingeben: 31.12.2026',
            default => null,
        };
        if ($warnung !== null || $zahlweise === null || $am === null) {
            $session->flash((string) $warnung, FlashArt::Warnung);

            return Response::redirect($ziel);
        }

        try {
            $bezahlt = $this->beitraege->mehrereBezahltVonHand($ids, $zahlweise, $am, $r->feld('vermerk'), $session->userId());
        } catch (BeitragNichtAenderbar $e) {
            $session->flash($e->getMessage(), FlashArt::Warnung);

            return Response::redirect($ziel);
        }

        $summe = array_sum(array_map(static fn(Jahresbeitrag $b): int => $b->betragCent, $bezahlt));
        $this->dienste->audit()->protokollieren(AuditAktion::BeitraegeBezahlt, $session->userId(), 'jahresbeitrag', null, [
            'anzahl' => count($bezahlt),
            'summe_cent' => $summe,
            'zahlweise' => $zahlweise->value,
            'ids' => array_map(static fn(Jahresbeitrag $b): int => (int) $b->id, $bezahlt),
        ], $r->ip);
        $session->flash(sprintf(
            '%s als bezahlt vermerkt (%s, %s am %s).',
            count($bezahlt) === 1 ? '1 Beitrag' : count($bezahlt) . ' Beiträge',
            Geld::anzeigen($summe),
            $zahlweise->bezeichnung(),
            Datum::anzeigen($am),
        ));

        return Response::redirect($ziel);
    }

    /** A year the page "Beiträge" offers, else null. */
    private static function jahr(string $eingabe): ?int
    {
        $jahr = preg_match('/^[0-9]{4}$/D', $eingabe) === 1 ? (int) $eingabe : null;

        return $jahr !== null && $jahr >= Jahresbeitraege::ERSTES_JAHR && $jahr <= Jahresbeitraege::letztesJahr(self::heute()) ? $jahr : null;
    }

    private static function heute(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE));
    }
}
