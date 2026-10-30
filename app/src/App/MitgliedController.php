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
use App\Repository\MitgliedRepository;
use App\Repository\MitgliedsnummerVergebenException;
use App\Repository\VersionKonfliktException;
use App\Service\Mitglied\HinweisLader;
use App\Service\Storage\BlobService;
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

    /** "Neues Mitglied": the empty form with the next free number (07 section 1). */
    public function neu(Request $r): Response
    {
        return $this->formularSeite(MitgliedFormular::neu($this->mitglieder->naechsteNummer()), null, Einbettung::ausAnfrage($r));
    }

    public function anlegen(Request $r): Response
    {
        $einbettung = Einbettung::ausAnfrage($r);
        $formular = MitgliedFormular::gelesen($r->post, new Mitglied(1, ''), $this->listen());
        if ($formular->mitglied === null) {
            return $this->formularSeite($formular, null, $einbettung, 422);
        }

        try {
            $ergebnis = $this->mitglieder->speichern($formular->mitglied, VersionsAnlass::angelegt(), $this->dienste->session->userId());
        } catch (MitgliedsnummerVergebenException) {
            return $this->formularSeite($this->nummerVergeben($formular), null, $einbettung, 422);
        }

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
        } catch (MitgliedsnummerVergebenException) {
            return $this->formularSeite($this->nummerVergeben($formular), $mitglied, $einbettung, 422);
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
     * The question before deleting (06 section 4, "Rückfragen"): in whole
     * sentences, what goes and what stays.
     *
     * @param array<string, string> $params
     */
    public function loeschenFragen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
        }

        return Response::html($this->dienste->view->render('mitglied/loeschen', [
            'title' => 'Mitglied löschen',
            'mitglied' => $mitglied,
            'ehemalig' => $mitglied->istEhemalig(new \DateTimeImmutable('today')),
            'einbettung' => Einbettung::ausAnfrage($r),
        ], Area::App));
    }

    /**
     * Deletes the member for good (admin only, 07 section 1): member,
     * versions, proofs with their files, annual fees. Direct debits keep
     * their own copy. Recorded in the audit log by id and number only.
     *
     * @param array<string, string> $params
     */
    public function loeschen(Request $r, array $params): Response
    {
        $mitglied = $this->mitglieder->finde((int) $params['id']);
        if ($mitglied === null) {
            return $this->gibtEsNicht();
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
        return Response::redirect(Einbettung::ausAnfrage($r)->rueckweg());
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
            'darfLoeschen' => $mitglied?->id !== null && $konto !== null && $konto->darf(Permission::MitgliederLoeschen),
            'einbettung' => $einbettung ?? Einbettung::mitgliederSeite(),
        ];
    }

    private function nummerVergeben(MitgliedFormular $formular): MitgliedFormular
    {
        $nummer = (int) $formular->wert('mitgliedsnummer');
        $andere = $this->mitglieder->findeNachNummer($nummer);

        return $formular->mitFehler('mitgliedsnummer', sprintf(
            'Die Nummer %d hat schon %s. Die nächste freie Nummer ist %d.',
            $nummer,
            $andere === null ? 'ein anderes Mitglied' : $andere->anzeigeName(),
            $this->mitglieder->naechsteNummer(),
        ));
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
