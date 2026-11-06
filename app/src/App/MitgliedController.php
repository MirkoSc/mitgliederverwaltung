<?php

declare(strict_types=1);

namespace App\App;

use App\App\Mitglied\Auswahllisten;
use App\App\Mitglied\Einbettung;
use App\App\Mitglied\MitgliedFormular;
use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Mitglied;
use App\Domain\Permission;
use App\Domain\VersionsAnlass;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FamilieRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\VersionKonfliktException;
use App\Service\Mitglied\HinweisLader;
use App\Service\Storage\BlobService;
use App\Support\Datum;
use App\View\Area;
use App\View\FlashArt;

/**
 * Create, change and delete a member (docs/spec/07-mitglieder.md sections 1,
 * 7 and 9; issue #19). The form itself is the tab "Daten"
 * (App\App\Mitglied\DatenReiter) on the members page; a new member gets the
 * same form on a page of its own.
 *
 * A save that works answers with a redirect to the member on the page it
 * was opened on (the field `zurueck`, App\App\Mitglied\Einbettung; the
 * members page without it) and the outcome as flash message (hints
 * included). A save that
 * cannot be done - a required field missing, the number taken, somebody
 * else saved in between - shows the form again on a page of its own, with
 * what was typed.
 */
final readonly class MitgliedController
{
    public const string PFAD_LISTE = '/app/mitglieder';
    public const string PFAD_NEU = '/app/mitglieder/neu';

    private const string KONFLIKT = 'Jemand anderes hat dieses Mitglied gespeichert, während Sie diese Seite offen hatten. '
        . 'Prüfen Sie den Stand und senden Sie die Seite erneut ab – dann gilt Ihre Angabe.';

    public function __construct(private Dienste $dienste, private MitgliedRepository $mitglieder)
    {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self($d, new MitgliedRepository($d->pdo())));
    }

    /** The member opened on the members page, on the tab $reiter (default: Daten). */
    public static function adresse(int $id, ?string $reiter = null): string
    {
        return self::PFAD_LISTE . '?mitglied=' . $id . ($reiter === null ? '' : '&reiter=' . rawurlencode($reiter));
    }

    public static function speicherAdresse(int $id): string
    {
        return self::PFAD_LISTE . '/' . $id;
    }

    public static function loeschAdresse(int $id): string
    {
        return self::PFAD_LISTE . '/' . $id . '/loeschen';
    }

    /** "Mitglied ausgetreten": the page with leaving date and reason. */
    public static function austrittAdresse(int $id): string
    {
        return self::PFAD_LISTE . '/' . $id . '/austritt';
    }

    /** "Austritt zurücknehmen": the question, then the post. */
    public static function zuruecknehmenAdresse(int $id): string
    {
        return self::PFAD_LISTE . '/' . $id . '/austritt-zuruecknehmen';
    }

    /** "Neues Mitglied": the empty form, showing the number the member will probably get (07 section 1). */
    public function neu(Request $r): Response
    {
        return $this->formularSeite(MitgliedFormular::neu($this->mitglieder->naechsteNummer()), null, Einbettung::ausAnfrage($r));
    }

    public function anlegen(Request $r): Response
    {
        $einbettung = Einbettung::ausAnfrage($r);
        $formular = MitgliedFormular::gelesen($r->post, new Mitglied(0, ''), $this->listen());
        if ($formular->mitglied === null) {
            return $this->formularSeite($formular, null, $einbettung, 422);
        }

        // The repository gives out the member number (07 section 1).
        $ergebnis = $this->mitglieder->speichern($formular->mitglied, VersionsAnlass::angelegt(), $this->dienste->session->userId());

        return $this->fertig($ergebnis->mitglied, 'Mitglied angelegt.', $einbettung);
    }

    /** @param array<string, string> $params */
    public function speichern(Request $r, array $params): Response
    {
        $gespeichert = $this->mitglieder->finde((int) $params['id']);
        if ($gespeichert === null) {
            return $this->gibtEsNicht();
        }

        $einbettung = Einbettung::ausAnfrage($r);
        $formular = MitgliedFormular::gelesen($r->post, $gespeichert, $this->listen());
        if ($formular->mitglied === null) {
            return $this->formularSeite($formular, $gespeichert->mit(['version' => self::version($r, $gespeichert)]), $einbettung, 422);
        }

        // The version the form was opened with: the optimistic lock (07 section 8).
        $mitglied = $formular->mitglied->mit(['version' => self::version($r, $gespeichert)]);
        try {
            $ergebnis = $this->mitglieder->speichern($mitglied, VersionsAnlass::geaendert(), $this->dienste->session->userId());
        } catch (VersionKonfliktException) {
            $aktuell = $this->mitglieder->finde($gespeichert->id ?? 0);
            if ($aktuell === null) {
                return $this->gibtEsNicht();
            }

            // The form keeps what was typed, the hidden version moves on to
            // the current one: saving again then means "mine wins", on purpose.
            return $this->formularSeite($formular, $aktuell, $einbettung, 409, 'Jemand anderes hat dieses Mitglied gespeichert, während Sie es bearbeitet haben. '
                . 'Unten stehen Ihre Eingaben. Öffnen Sie das Mitglied neu, um den anderen Stand zu sehen, oder speichern Sie Ihre Eingaben erneut – dann gelten sie.');
        }

        return $this->fertig(
            $ergebnis->mitglied,
            $ergebnis->neueVersion ? 'Mitglied gespeichert.' : 'Nichts geändert – das Mitglied war schon so gespeichert.',
            $einbettung,
        );
    }

    /**
     * "Mitglied ausgetreten" (07 section 1): leaving date (today by default)
     * and reason on a page of their own. A date in the future only notes the
     * leaving - until then the person stays a member.
     *
     * @param array<string, string> $params
     */
    public function austrittFragen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }

        return $this->austrittSeite($mitglied, [
            'austrittsdatum' => ($mitglied->austrittsdatum ?? Datum::heute())->format('Y-m-d'),
            'austrittsgrund' => $mitglied->austrittsgrund,
        ], Einbettung::ausAnfrage($r));
    }

    /**
     * Saves leaving date and reason as an ordinary save of the member: a
     * new version, the optimistic lock of the form (07 section 8). Audited
     * by id only.
     *
     * @param array<string, string> $params
     */
    public function austritt(Request $r, array $params): Response
    {
        $gespeichert = $this->mitglieder->finde((int) $params['id']);
        if ($gespeichert === null) {
            return $this->gibtEsNicht();
        }
        $einbettung = Einbettung::ausAnfrage($r);
        $werte = [
            'austrittsdatum' => $r->feld('austrittsdatum'),
            'austrittsgrund' => mb_substr((string) preg_replace('/\s+/u', ' ', $r->feld('austrittsgrund')), 0, MitgliedFormular::LAENGEN['austrittsgrund']),
        ];
        $datum = Datum::ausEingabe($werte['austrittsdatum']);
        $version = self::version($r, $gespeichert);
        if ($datum === null) {
            return $this->austrittSeite($gespeichert->mit(['version' => $version]), $werte, $einbettung, 422, fehler: $werte['austrittsdatum'] === ''
                ? 'Bitte das Austrittsdatum eintragen.'
                : 'Bitte das Datum als Tag, Monat und Jahr eintragen, zum Beispiel 31.12.2026.');
        }

        $mitglied = $gespeichert->mit(['austrittsdatum' => $datum, 'austrittsgrund' => $werte['austrittsgrund'], 'version' => $version]);
        try {
            $ergebnis = $this->mitglieder->speichern($mitglied, VersionsAnlass::geaendert(), $this->dienste->session->userId());
        } catch (VersionKonfliktException) {
            $aktuell = $this->mitglieder->finde((int) $gespeichert->id);

            return $aktuell === null ? $this->gibtEsNicht() : $this->austrittSeite($aktuell, $werte, $einbettung, 409, meldung: self::KONFLIKT);
        }
        if ($ergebnis->neueVersion) {
            $this->protokollieren(AuditAktion::MitgliedAusgetreten, $ergebnis->mitglied, $r);
        }

        return $this->fertig($ergebnis->mitglied, $ergebnis->mitglied->istEhemalig(Datum::heute())
            ? sprintf('Austritt zum %s eingetragen – das Mitglied ist jetzt ehemalig.', Datum::anzeigen($datum))
            : sprintf('Austritt zum %s vermerkt – bis dahin bleibt es Mitglied.', Datum::anzeigen($datum)), $einbettung);
    }

    /**
     * The short question before "Austritt zurücknehmen". A member without
     * a leaving date has nothing to take back: straight back to it.
     *
     * @param array<string, string> $params
     */
    public function zuruecknehmenFragen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        $einbettung = Einbettung::ausAnfrage($r);
        if ($mitglied->austrittsdatum === null) {
            return Response::redirect($einbettung->adresse((int) $mitglied->id));
        }

        return $this->zuruecknehmenSeite($mitglied, $einbettung);
    }

    /**
     * Clears leaving date and reason: a new version, the old state stays
     * in the history. Audited by id only.
     *
     * @param array<string, string> $params
     */
    public function zuruecknehmen(Request $r, array $params): Response
    {
        $gespeichert = $this->mitglieder->finde((int) $params['id']);
        if ($gespeichert === null) {
            return $this->gibtEsNicht();
        }
        $einbettung = Einbettung::ausAnfrage($r);
        if ($gespeichert->austrittsdatum === null) {
            $this->dienste->session->flash('Für dieses Mitglied ist kein Austritt eingetragen.', FlashArt::Warnung);

            return Response::redirect($einbettung->adresse((int) $gespeichert->id));
        }

        $mitglied = $gespeichert->mit(['austrittsdatum' => null, 'austrittsgrund' => '', 'version' => self::version($r, $gespeichert)]);
        try {
            $ergebnis = $this->mitglieder->speichern($mitglied, VersionsAnlass::geaendert(), $this->dienste->session->userId());
        } catch (VersionKonfliktException) {
            $aktuell = $this->mitglieder->finde((int) $gespeichert->id);
            if ($aktuell === null) {
                return $this->gibtEsNicht();
            }
            if ($aktuell->austrittsdatum === null) {
                return Response::redirect($einbettung->adresse((int) $aktuell->id));
            }

            return $this->zuruecknehmenSeite($aktuell, $einbettung, 409, self::KONFLIKT);
        }
        $this->protokollieren(AuditAktion::AustrittZurueckgenommen, $ergebnis->mitglied, $r);

        return $this->fertig($ergebnis->mitglied, 'Austritt zurückgenommen.', $einbettung);
    }

    /**
     * Deleting, steps 1 and 2 (06 section 4, "Rückfragen"; 07 section 1):
     * first the question in whole sentences - what goes, what stays -,
     * then ("Weiter", ?schritt=2) "Endgültig löschen?" with the member
     * number to type. Only a former member can be deleted; for anyone else
     * the page says so (409).
     *
     * @param array<string, string> $params
     */
    public function loeschenFragen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }

        return $this->loeschSeite($mitglied, Einbettung::ausAnfrage($r), $r->feld('schritt') === '2' ? 2 : 1);
    }

    /**
     * Deletes the member for good (admin only, 07 section 1): member,
     * versions, proofs with their files, annual fees. Direct debits keep
     * their own copy. Only a former member, and only with its member
     * number typed in step 2 - checked here, not only in the browser.
     * Recorded in the audit log by id and number only.
     *
     * @param array<string, string> $params
     */
    public function loeschen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }
        $einbettung = Einbettung::ausAnfrage($r);
        if (!$mitglied->istEhemalig(Datum::heute())) {
            return $this->loeschSeite($mitglied, $einbettung, 1);
        }
        $eingabe = $r->feld('mitgliedsnummer');
        if (!ctype_digit($eingabe) || ltrim($eingabe, '0') !== (string) $mitglied->mitgliedsnummer) {
            return $this->loeschSeite($mitglied, $einbettung, 2, $eingabe === ''
                ? 'Bitte die Mitgliedsnummer eintippen. Es wurde nichts gelöscht.'
                : 'Die Mitgliedsnummer stimmt nicht. Es wurde nichts gelöscht.');
        }
        $id = (int) $mitglied->id;

        $blobIds = $this->mitglieder->loeschen($id);
        $this->dateienLoeschen($blobIds);
        $this->dienste->audit()->protokollieren(
            AuditAktion::MitgliedGeloescht,
            $this->dienste->session->userId(),
            'mitglied',
            $id,
            ['mitgliedsnummer' => $mitglied->mitgliedsnummer],
            $r->ip,
        );

        $this->dienste->session->flash(sprintf('Mitglied Nr. %d ist gelöscht.', $mitglied->mitgliedsnummer));

        // Back to the page it was opened on, without a member.
        return Response::redirect($einbettung->rueckweg());
    }

    /**
     * @param array{austrittsdatum: string, austrittsgrund: string} $werte
     */
    private function austrittSeite(Mitglied $mitglied, array $werte, Einbettung $einbettung, int $status = 200, ?string $fehler = null, ?string $meldung = null): Response
    {
        return Response::html($this->dienste->view->render('mitglied/austritt', [
            'title' => 'Mitglied ausgetreten',
            'mitglied' => $mitglied,
            'werte' => $werte,
            'fehler' => $fehler,
            'meldung' => $meldung,
            'einbettung' => $einbettung,
        ], Area::App), $status);
    }

    private function zuruecknehmenSeite(Mitglied $mitglied, Einbettung $einbettung, int $status = 200, ?string $meldung = null): Response
    {
        return Response::html($this->dienste->view->render('mitglied/austritt-zuruecknehmen', [
            'title' => 'Austritt zurücknehmen',
            'mitglied' => $mitglied,
            'ehemalig' => $mitglied->istEhemalig(Datum::heute()),
            'meldung' => $meldung,
            'einbettung' => $einbettung,
        ], Area::App), $status);
    }

    /** Step 1 or 2 of deleting; a member that has not left (yet) gets the reason why not, 409. */
    private function loeschSeite(Mitglied $mitglied, Einbettung $einbettung, int $schritt, ?string $fehler = null): Response
    {
        $ehemalig = $mitglied->istEhemalig(Datum::heute());

        return Response::html($this->dienste->view->render('mitglied/loeschen', [
            'title' => 'Mitglied löschen',
            'mitglied' => $mitglied,
            'ehemalig' => $ehemalig,
            'schritt' => $schritt,
            'fehler' => $fehler,
            'einbettung' => $einbettung,
        ], Area::App), match (true) {
            !$ehemalig => 409,
            $fehler !== null => 422,
            default => 200,
        });
    }

    /** Leaving entered or taken back: by id and version, never with a name or date (02 section 8). */
    private function protokollieren(AuditAktion $aktion, Mitglied $mitglied, Request $r): void
    {
        $this->dienste->audit()->protokollieren($aktion, $this->dienste->session->userId(), 'mitglied', $mitglied->id, ['version' => $mitglied->version], $r->ip);
    }

    private function listen(): Auswahllisten
    {
        return Auswahllisten::aus($this->dienste);
    }

    /**
     * The form on a page of its own: "Neues Mitglied", or a member whose
     * save could not be done.
     */
    private function formularSeite(MitgliedFormular $formular, ?Mitglied $mitglied, Einbettung $einbettung, int $status = 200, ?string $meldung = null): Response
    {
        $titel = $mitglied === null ? 'Neues Mitglied' : 'Mitglied bearbeiten';

        return Response::html($this->dienste->view->render('mitglied/seite', [
            'title' => $titel,
            'meldung' => $meldung,
            // Address suggestions and IBAN/BIC check (issue #127); the other scripts of MitgliedAnsicht belong to the opened member.
            'scripts' => ['/js/anschrift-vorschlaege.js', '/js/bankverbindung.js'],
            ...$this->formularDaten($formular, $mitglied, $einbettung),
        ], Area::App), $status);
    }

    /**
     * What the template mitglied/formular needs - shared with the tab
     * "Daten" (App\App\Mitglied\DatenReiter, which gets its Einbettung
     * from MitgliedAnsicht). Cancel and delete lead back to $einbettung.
     *
     * @return array<string, mixed>
     */
    public function formularDaten(MitgliedFormular $formular, ?Mitglied $mitglied, ?Einbettung $einbettung = null): array
    {
        $konto = $this->dienste->view->konto();
        $familie = $mitglied?->familieId === null ? null : new FamilieRepository($this->dienste->pdo())->finde($mitglied->familieId);

        return [
            'formular' => $formular,
            'listen' => $this->listen(),
            'mitglied' => $mitglied,
            'familie' => $familie,
            'aktion' => $mitglied?->id === null ? self::PFAD_NEU : self::speicherAdresse($mitglied->id),
            'darfBearbeiten' => $konto !== null && $konto->darf(Permission::MitgliederBearbeiten),
            // Only a former member can be deleted (07 section 1); everyone else leaves first.
            'darfLoeschen' => $mitglied?->id !== null && $konto !== null && $konto->darf(Permission::MitgliederLoeschen) && $mitglied->istEhemalig(Datum::heute()),
            'einbettung' => $einbettung ?? Einbettung::mitgliederSeite(),
            // Next to the remark, not among the hints on top (#133); fee data, so only with beitraege.sehen.
            'ruecklastschriftJahre' => $mitglied?->id !== null && $konto !== null && $konto->darf(Permission::BeitraegeSehen)
                ? new JahresbeitragRepository($this->dienste->pdo())->ruecklastschriftJahre($mitglied->id)[$mitglied->id] ?? []
                : [],
        ];
    }

    private function fertig(Mitglied $mitglied, string $meldung, Einbettung $einbettung): Response
    {
        // The hints themselves stand at the top of the opened member (07
        // section 6, HinweiseAbschnitt); the message only says there are some.
        $anzahl = count(HinweisLader::aus($this->dienste->pdo())->fuer($mitglied));
        $this->dienste->session->flash(
            $anzahl === 0 ? $meldung : sprintf(
                '%s %s',
                $meldung,
                $anzahl === 1 ? 'Es gibt einen Hinweis – er steht oben im Mitglied.' : sprintf('Es gibt %d Hinweise – sie stehen oben im Mitglied.', $anzahl),
            ),
            $anzahl === 0 ? FlashArt::Ok : FlashArt::Warnung,
        );

        return Response::redirect($einbettung->adresse((int) $mitglied->id));
    }

    private function gibtEsNicht(): Response
    {
        return Response::html($this->dienste->view->render('fehler', [
            'title' => 'Mitglied nicht gefunden',
            'meldung' => 'Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es inzwischen gelöscht.',
            'startseite' => self::PFAD_LISTE,
        ], Area::App), 404);
    }

    /**
     * The files of deleted proofs: the database rows are gone with the
     * member, the encrypted files are removed here. A file that cannot be
     * removed is logged, not shown - the member is deleted either way.
     *
     * @param list<int> $blobIds
     */
    private function dateienLoeschen(array $blobIds): void
    {
        if ($blobIds === []) {
            return;
        }
        $blobs = BlobService::aus($this->dienste);
        foreach ($blobIds as $blobId) {
            try {
                $blob = $blobs->find($blobId);
                if ($blob !== null) {
                    $blobs->delete($blob);
                }
            } catch (\Throwable $fehler) {
                $this->dienste->logger->append(sprintf('Proof file %d of a deleted member could not be removed: %s', $blobId, $fehler::class));
            }
        }
    }

    private static function version(Request $r, Mitglied $gespeichert): int
    {
        $wert = $r->feld('version');

        return ctype_digit($wert) ? (int) $wert : $gespeichert->version;
    }
}
