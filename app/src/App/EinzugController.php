<?php

declare(strict_types=1);

namespace App\App;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Einzug;
use App\Domain\EinzugStatus;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\StreamResponse;
use App\Repository\BenutzerRepository;
use App\Repository\EinzugRepository;
use App\Repository\JahresbeitragRepository;
use App\Service\Beitraege\Bankarbeitstage;
use App\Service\Beitraege\EinzugNichtMoeglich;
use App\Service\Beitraege\Einzuege;
use App\Support\Datum;
use App\Support\Geld;
use App\View\Area;
use App\View\FlashArt;
use App\View\Navigation;

/**
 * The direct debit on the page "Beiträge" (docs/spec/08-beitraege.md
 * section 3, 06 section 2): compose and create, download the file, confirm
 * or discard, and the archive of every run below - plus the page of one run
 * with the fees it holds. Admin only; every route declares
 * BeitraegeVerwalten (app/routes/einzug.php).
 *
 * The page "Beiträge" itself belongs to BeitraegeController; it adds
 * seitenDaten() to what it renders.
 */
final readonly class EinzugController
{
    public const string PFAD = Navigation::BEITRAEGE . '/einzuege';

    /** The checkbox list arrives complete only with this field, sent last. */
    public const string VOLLSTAENDIG = 'auswahl_vollstaendig';

    public function __construct(private Dienste $dienste, private Einzuege $service, private EinzugRepository $einzuege)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d,
            Einzuege::aus($d),
            new EinzugRepository($d->pdo()),
        ));
    }

    /** The page of one run. */
    public static function adresse(int $id): string
    {
        return self::PFAD . '/' . $id;
    }

    public static function dateiAdresse(int $id): string
    {
        return self::adresse($id) . '/datei';
    }

    /**
     * What the page "Beiträge" shows of the direct debit: the compose list,
     * the runs waiting for the bank and the archive of all runs.
     *
     * @return array<string, mixed>
     */
    public function seitenDaten(): array
    {
        $einzuege = $this->einzuege->alle(mitDaten: false);

        return [
            'einzugAuswahl' => $this->service->zusammenstellen(),
            'einzuegeOffen' => array_values(array_filter($einzuege, static fn(Einzug $e): bool => !$e->abgeschlossen())),
            'einzuegeAlle' => $einzuege,
            'einzugNamen' => $this->namen($einzuege),
        ];
    }

    /** Step 2: create the run from the chosen fees. */
    public function erzeugen(Request $r): Response
    {
        $session = $this->dienste->session;
        if ($r->feld(self::VOLLSTAENDIG) !== '1') {
            $session->flash('Die Auswahl kam nicht vollständig beim Server an, es wurde nichts erzeugt. '
                . 'Bitte versuchen Sie es erneut; bei sehr vielen Beiträgen wählen Sie einen Teil ab und erzeugen zwei Einzüge.', FlashArt::Warnung);

            return Response::redirect(Navigation::BEITRAEGE . '#einzug');
        }
        $faellig = Datum::ausEingabe($r->feld('faellig_am'));
        if ($faellig === null) {
            $session->flash('Bitte geben Sie ein gültiges Fälligkeitsdatum ein, zum Beispiel 13.02.2026.', FlashArt::Warnung);

            return Response::redirect(Navigation::BEITRAEGE . '#einzug');
        }
        $auswahl = $r->post['beitrag'] ?? [];
        $ids = is_array($auswahl) ? array_values(array_map(intval(...), array_filter($auswahl, is_string(...)))) : [];

        try {
            $einzug = $this->service->erzeugen($ids, $faellig, $session->userId());
        } catch (EinzugNichtMoeglich $e) {
            $session->flash($e->getMessage(), FlashArt::Warnung);

            return Response::redirect(Navigation::BEITRAEGE . '#einzug');
        }

        $meldung = sprintf(
            'Einzug Nr. %d erzeugt: %s, %s. Laden Sie jetzt die Lastschriftdatei herunter und reichen Sie sie im Online-Banking ein.',
            (int) $einzug->id,
            $einzug->anzahl === 1 ? '1 Beitrag' : $einzug->anzahl . ' Beiträge',
            Geld::anzeigen($einzug->summeCent),
        );
        $fruehestens = Bankarbeitstage::naechster(self::heute());
        $achtung = match (true) {
            $faellig < $fruehestens => sprintf(
                'Die Fälligkeit %s liegt vor dem nächsten Bankarbeitstag (%s).',
                Datum::anzeigen($faellig),
                Datum::anzeigen($fruehestens),
            ),
            !Bankarbeitstage::istBankarbeitstag($faellig) => sprintf('Der %s ist kein Bankarbeitstag.', Datum::anzeigen($faellig)),
            default => null,
        };
        $session->flash(
            $achtung === null ? $meldung : $meldung . ' Achtung: ' . $achtung
                . ' Nimmt die Bank die Datei so nicht an, verwerfen Sie den Einzug und erzeugen Sie ihn mit einem anderen Datum neu.',
            $achtung === null ? FlashArt::Ok : FlashArt::Warnung,
        );

        // Its own page: the message on top, the download right below it.
        return Response::redirect(self::adresse((int) $einzug->id));
    }

    /** @param array<string, string> $params */
    public function seite(Request $r, array $params): Response
    {
        $einzug = $this->einzuege->finde((int) $params['id']);
        if ($einzug === null) {
            return $this->nichtGefunden();
        }
        $posten = $einzug->posten();
        $beitraege = new JahresbeitragRepository($this->dienste->pdo())
            ->mitIds(array_map(static fn($p): int => $p->beitragId, $posten));

        return Response::html($this->dienste->view->render('beitraege/einzug-seite', [
            'title' => 'Einzug Nr. ' . $einzug->id,
            'einzug' => $einzug,
            'posten' => $posten,
            'beitraege' => $beitraege,
            'einzugNamen' => $this->namen([$einzug]),
            'dateiVorhanden' => $this->service->datei($einzug) !== null,
            'hier' => self::adresse((int) $einzug->id),
        ], Area::App));
    }

    /**
     * Step 3: the stored file - every time the same bytes.
     *
     * @param array<string, string> $params
     */
    public function datei(Request $r, array $params): ResponseInterface
    {
        $einzug = $this->einzuege->finde((int) $params['id']);
        $blob = $einzug === null ? null : $this->service->datei($einzug);
        if ($einzug === null || $blob === null) {
            return $this->nichtGefunden('Zu diesem Einzug gibt es keine Lastschriftdatei.');
        }
        // The file holds the IBANs of nearly every member (02 section 8).
        $this->dienste->audit()->protokollieren(AuditAktion::EinzugDateiHeruntergeladen, $this->dienste->session->userId(), 'einzug', $einzug->id, ip: $r->ip);
        // The session lock would block the person's other tabs for the whole download.
        $this->dienste->session->schliessen();

        return new StreamResponse($this->service->dateiLesen($blob), [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . Einzuege::dateiname($einzug) . '"',
            'Content-Length' => (string) $blob->size,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, string> $params */
    public function bestaetigen(Request $r, array $params): Response
    {
        return $this->abschliessen($r, (int) $params['id'], EinzugStatus::Bestaetigt);
    }

    /** @param array<string, string> $params */
    public function verwerfen(Request $r, array $params): Response
    {
        return $this->abschliessen($r, (int) $params['id'], EinzugStatus::Verworfen);
    }

    /**
     * Where a form of a run returns to: its page, or the page "Beiträge" -
     * nothing else (no open redirect).
     */
    public static function zurueck(string $eingabe, int $id): string
    {
        return $eingabe === self::adresse($id) ? $eingabe : Navigation::BEITRAEGE;
    }

    private function abschliessen(Request $r, int $id, EinzugStatus $status): Response
    {
        $ziel = self::zurueck($r->feld('zurueck'), $id);
        $session = $this->dienste->session;
        if ($r->feld('sicher') !== '1') {
            $session->flash($status === EinzugStatus::Bestaetigt
                ? 'Bitte bestätigen Sie mit dem Häkchen, dass die Bank den Einzug ausgeführt hat.'
                : 'Bitte bestätigen Sie mit dem Häkchen, dass die Datei nicht bei der Bank eingereicht wurde.', FlashArt::Warnung);

            return Response::redirect($ziel);
        }

        try {
            $einzug = $status === EinzugStatus::Bestaetigt
                ? $this->service->bestaetigen($id, $session->userId())
                : $this->service->verwerfen($id, $session->userId());
        } catch (\OutOfBoundsException) {
            return $this->nichtGefunden();
        } catch (EinzugNichtMoeglich $e) {
            $session->flash($e->getMessage(), FlashArt::Warnung);

            return Response::redirect($ziel);
        }

        $session->flash($status === EinzugStatus::Bestaetigt
            ? sprintf('Einzug Nr. %d bestätigt: Die Beiträge sind zum %s bezahlt.', $id, Datum::anzeigen($einzug->faelligAm))
            : sprintf('Einzug Nr. %d verworfen: Die Beiträge sind wieder offen und können erneut eingezogen werden.', $id));

        return Response::redirect($ziel);
    }

    /**
     * Names of the accounts that created or closed these runs, by id; an
     * account deleted since has none (the run keeps its id, 08 section 6).
     *
     * @param list<Einzug> $einzuege
     * @return array<int, string|null>
     */
    private function namen(array $einzuege): array
    {
        $benutzer = new BenutzerRepository($this->dienste->pdo());
        $namen = [];
        foreach ($einzuege as $einzug) {
            foreach ([$einzug->erstelltVon, $einzug->bestaetigtVon] as $id) {
                if ($id !== null && !array_key_exists($id, $namen)) {
                    $namen[$id] = $benutzer->findById($id)?->name;
                }
            }
        }

        return $namen;
    }

    private function nichtGefunden(string $meldung = 'Diesen Einzug gibt es nicht.'): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Einzug nicht gefunden',
            'meldung' => $meldung,
            'startseite' => Navigation::BEITRAEGE,
        ], Area::App), 404);
    }

    private static function heute(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE));
    }
}
