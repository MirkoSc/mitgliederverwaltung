<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

use App\Dienste;
use App\Domain\AuditAktion;
use App\Domain\Blob;
use App\Domain\BlobMeta;
use App\Domain\Einzug;
use App\Domain\EinzugPosten;
use App\Domain\EinzugStatus;
use App\Domain\Jahresbeitrag;
use App\Domain\JahresbeitragStatus;
use App\Domain\Mitglied;
use App\Domain\Vermerk;
use App\Domain\Zahlart;
use App\Repository\EinzugRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\Zeilen;
use App\Service\Audit\AuditLog;
use App\Service\Beitraege\Sepa\Pain008;
use App\Service\Beitraege\Sepa\Pain008Auftrag;
use App\Service\Beitraege\Sepa\Pain008Lastschrift;
use App\Service\Beitraege\Sepa\SepaText;
use App\Service\Storage\BlobService;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;
use App\Support\Datum;
use App\Support\Iban;

/**
 * The direct debit (docs/spec/08-beitraege.md sections 3 and 4): compose
 * from the open fees, create - the fees go to `im_einzug` and the pain.008
 * file is made exactly once and stored encrypted - then confirm (paid on
 * the due date) or discard (back to open). Admin only - the routes check
 * BeitraegeVerwalten.
 *
 * The stored file is the archive of what went to the bank: it is never
 * made again and never changed, every download is the same bytes, whatever
 * happens to the members afterwards. Runs and their files are deleted
 * only together with the fees of their year (BeitragsjahrLoeschen, #90).
 */
final readonly class Einzuege
{
    /** Remittance text when the club data has no template of its own (08 section 4). */
    public const string VERWENDUNGSZWECK = 'Mitgliedsbeitrag {jahr} Nr. {mitgliedsnummer} {name}';

    /** The placeholders of the template, as the page explains them. */
    public const array PLATZHALTER = [
        '{jahr}' => 'Beitragsjahr',
        '{mitgliedsnummer}' => 'Mitgliedsnummer',
        '{name}' => 'Vor- und Nachname des Mitglieds',
    ];

    public function __construct(
        private \PDO $pdo,
        private EinzugRepository $einzuege,
        private JahresbeitragRepository $beitraege,
        private MitgliedRepository $mitglieder,
        private VereinRepository $verein,
        private BlobService $blobs,
        private AuditLog $audit,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static fn(Dienste $d): self => new self(
            $d->pdo(),
            new EinzugRepository($d->pdo()),
            new JahresbeitragRepository($d->pdo()),
            new MitgliedRepository($d->pdo()),
            VereinRepository::aus($d),
            BlobService::aus($d),
            $d->audit(),
        ));
    }

    /**
     * Step 1: every open fee, the collectable ones (Zahlart `lastschrift`,
     * valid IBAN, a usable mandate) apart from the others, plus what the
     * club data lacks.
     */
    public function zusammenstellen(?\DateTimeImmutable $heute = null): EinzugAuswahl
    {
        $heute = ($heute ?? self::jetzt())->setTime(0, 0);
        $verein = $this->verein->get();
        $mitglieder = $this->mitgliederNachId();

        $kandidaten = [];
        foreach ($this->beitraege->mitStatus(JahresbeitragStatus::Offen) as $beitrag) {
            $mitglied = $mitglieder[$beitrag->mitgliedId] ?? null;
            if ($mitglied !== null) {
                $kandidaten[] = self::kandidat($beitrag, $mitglied, $verein, $heute);
            }
        }
        usort($kandidaten, self::reihenfolge(...));

        return new EinzugAuswahl(
            auswaehlbar: array_values(array_filter($kandidaten, static fn(EinzugKandidat $k): bool => $k->auswaehlbar())),
            nichtAuswaehlbar: array_values(array_filter($kandidaten, static fn(EinzugKandidat $k): bool => !$k->auswaehlbar())),
            fehlendeVereinsdaten: self::fehlendeVereinsdaten($verein),
            vorschlagFaelligkeit: Bankarbeitstage::vorschlag($heute),
            verwendungszweck: self::vorlage($verein),
        );
    }

    /**
     * Step 2: creates the run from the chosen open fees. In one
     * transaction: the run, its file (made once, stored encrypted) and the
     * fees going to `im_einzug` with a note each. If a chosen fee is no
     * longer open or collectable, nothing happens - the page shows the
     * current state again.
     *
     * @param list<int> $beitragIds
     * @throws EinzugNichtMoeglich
     */
    public function erzeugen(array $beitragIds, \DateTimeImmutable $faelligAm, ?int $von, ?\DateTimeImmutable $jetzt = null): Einzug
    {
        // Whole seconds: the file's CreDtTm and erstellt_am are the same moment.
        $jetzt ??= self::jetzt();
        $jetzt = $jetzt->setTime((int) $jetzt->format('H'), (int) $jetzt->format('i'), (int) $jetzt->format('s'));
        $faelligAm = $faelligAm->setTime(0, 0);
        $verein = $this->verein->get();
        $fehlend = self::fehlendeVereinsdaten($verein);
        if ($fehlend !== []) {
            throw new EinzugNichtMoeglich('Für die Lastschriftdatei fehlt in den Vereinsdaten: ' . implode(', ', $fehlend) . '.');
        }
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $beitragIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new EinzugNichtMoeglich('Bitte wählen Sie mindestens einen Beitrag für den Einzug aus.');
        }

        $blob = null;
        try {
            $einzug = Zeilen::transaktion($this->pdo, function () use ($ids, $faelligAm, $von, $jetzt, $verein, &$blob): Einzug {
                $kandidaten = [];
                $beitraege = $this->beitraege->mitIds($ids, zumAendern: true);
                // Read after the lock, so the file carries the members as they are now.
                $mitglieder = $this->mitgliederNachId();
                foreach ($ids as $id) {
                    $beitrag = $beitraege[$id] ?? null;
                    $mitglied = $beitrag === null ? null : ($mitglieder[$beitrag->mitgliedId] ?? null);
                    $kandidat = $beitrag === null || $mitglied === null ? null : self::kandidat($beitrag, $mitglied, $verein, $jetzt);
                    if ($kandidat === null || !$kandidat->auswaehlbar() || $kandidat->beitrag->status !== JahresbeitragStatus::Offen) {
                        throw new EinzugNichtMoeglich('Mindestens ein gewählter Beitrag ist inzwischen nicht mehr offen oder nicht mehr einziehbar. '
                            . 'Die Liste ist jetzt wieder aktuell – bitte prüfen Sie die Auswahl und erzeugen Sie den Einzug erneut.');
                    }
                    $kandidaten[] = $kandidat;
                }
                usort($kandidaten, self::reihenfolge(...));

                $einzug = $this->einzuege->anlegen(new Einzug(
                    status: EinzugStatus::Erzeugt,
                    faelligAm: $faelligAm,
                    erstelltVon: $von,
                    erstelltAm: $jetzt,
                    anzahl: count($kandidaten),
                    summeCent: array_sum(array_map(static fn(EinzugKandidat $k): int => $k->beitrag->betragCent, $kandidaten)),
                ));
                $id = (int) $einzug->id;

                $lastschriften = [];
                $posten = [];
                foreach ($kandidaten as $k) {
                    $lastschrift = self::lastschrift($k, $verein, $id, $jetzt);
                    $lastschriften[] = $lastschrift;
                    $posten[] = new EinzugPosten(
                        beitragId: (int) $k->beitrag->id,
                        mitgliedId: (int) $k->mitglied->id,
                        mitgliedsnummer: $k->mitglied->mitgliedsnummer,
                        name: $k->name(),
                        jahr: $k->beitrag->jahr,
                        betragCent: $k->beitrag->betragCent,
                        mandatsreferenz: $lastschrift->mandatsreferenz,
                        endToEndId: $lastschrift->endToEndId,
                    )->alsArray();
                }

                $msgId = sprintf('E%d-%s', $id, $jetzt->format('YmdHis'));
                try {
                    $xml = Pain008::xml(new Pain008Auftrag(
                        msgId: $msgId,
                        pmtInfId: 'E' . $id,
                        erstellt: $jetzt,
                        glaeubiger: self::glaeubiger($verein),
                        iban: Iban::normalisieren($verein->wert('iban')),
                        bic: self::bic($verein->wert('bic')),
                        glaeubigerId: $verein->wert('glaeubiger_id'),
                        faelligAm: $faelligAm,
                        lastschriften: $lastschriften,
                    ));
                } catch (\InvalidArgumentException $e) {
                    throw new EinzugNichtMoeglich('Die Lastschriftdatei ließ sich nicht erzeugen: ' . $e->getMessage(), 0, $e);
                }

                $blob = $this->blobs->storeString($xml, new BlobMeta('application/xml', self::dateiname($einzug)));
                if (!$this->einzuege->dateiSetzen($id, $blob->id, $msgId, $posten)) {
                    throw new \LogicException('A new direct debit already had a file.');
                }

                $text = sprintf('In Einzug Nr. %d aufgenommen, fällig am %s', $id, Datum::anzeigen($faelligAm));
                foreach ($kandidaten as $k) {
                    $this->beitraege->speichern($k->beitrag->inEinzug($id)->mitVermerk(new Vermerk($von, $jetzt, $text)));
                }

                return $einzug->mit(['msgId' => $msgId, 'dateiBlobId' => $blob->id, 'daten' => $posten]);
            });
        } catch (\Throwable $fehler) {
            // The rollback took the rows back; a file the fs backend wrote
            // meanwhile must not stay behind.
            if ($blob instanceof Blob) {
                try {
                    $this->blobs->delete($blob);
                } catch (\Throwable) {
                    // Nothing left to do - an orphaned ciphertext is harmless.
                }
            }

            throw $fehler;
        }

        $this->audit->protokollieren(AuditAktion::EinzugErzeugt, $von, 'einzug', $einzug->id, [
            'anzahl' => $einzug->anzahl,
            'summe_cent' => $einzug->summeCent,
        ]);

        return $einzug;
    }

    /**
     * Step 4: the bank has collected - every fee of the run that is still
     * in it is paid, on the due date.
     *
     * @throws \OutOfBoundsException no such run
     * @throws EinzugNichtMoeglich already closed
     */
    public function bestaetigen(int $id, ?int $von, ?\DateTimeImmutable $jetzt = null): Einzug
    {
        return $this->abschliessen($id, EinzugStatus::Bestaetigt, $von, $jetzt ?? self::jetzt());
    }

    /**
     * Step 4, the other way: the file never went to the bank - every fee
     * of the run is open again. The run and its file stay in the archive.
     *
     * @throws \OutOfBoundsException no such run
     * @throws EinzugNichtMoeglich already closed
     */
    public function verwerfen(int $id, ?int $von, ?\DateTimeImmutable $jetzt = null): Einzug
    {
        return $this->abschliessen($id, EinzugStatus::Verworfen, $von, $jetzt ?? self::jetzt());
    }

    /** The stored file of a run; null when it is missing (never for a run made here). */
    public function datei(Einzug $einzug): ?Blob
    {
        $blob = $einzug->dateiBlobId === null ? null : $this->blobs->find($einzug->dateiBlobId);

        return $blob !== null && $blob->isComplete() ? $blob : null;
    }

    /**
     * The decrypted file in pieces, for the download - always the same
     * bytes.
     *
     * @return \Generator<string>
     */
    public function dateiLesen(Blob $blob): \Generator
    {
        return $this->blobs->openRead($blob);
    }

    /** `lastschrift-<jahr>-<einzug-id>.xml`, the year of the due date - no names (08 section 4). */
    public static function dateiname(Einzug $einzug): string
    {
        return sprintf('lastschrift-%s-%d.xml', $einzug->faelligAm->format('Y'), (int) $einzug->id);
    }

    /**
     * What the club data lacks for a file; [] = nothing. Without creditor
     * identifier and IBAN - and a name for the creditor - the file would be
     * worthless, so this is the one real block (08 section 4).
     *
     * @return list<string>
     */
    public static function fehlendeVereinsdaten(Vereinsdaten $verein): array
    {
        $fehlend = [];
        $iban = $verein->wert('iban');
        if ($iban === '') {
            $fehlend[] = 'die IBAN des Vereinskontos';
        } elseif (!Iban::istGueltig($iban)) {
            $fehlend[] = 'eine gültige IBAN des Vereinskontos (die eingetragene hat eine falsche Prüfziffer)';
        }
        $glaeubigerId = $verein->wert('glaeubiger_id');
        if ($glaeubigerId === '') {
            $fehlend[] = 'die Gläubiger-ID';
        } elseif (!Pain008::istGlaeubigerId($glaeubigerId)) {
            $fehlend[] = 'eine gültige Gläubiger-ID (Form wie DE98ZZZ09999999999)';
        }
        if (self::glaeubiger($verein) === '') {
            $fehlend[] = 'der Kontoinhaber oder Vereinsname';
        }

        return $fehlend;
    }

    /**
     * The remittance text of one fee: the template with its placeholders
     * filled, in the SEPA character set, at most 140 characters.
     */
    public static function verwendungszweck(string $vorlage, int $jahr, Mitglied $mitglied): string
    {
        $text = strtr($vorlage, [
            '{jahr}' => (string) $jahr,
            '{mitgliedsnummer}' => (string) $mitglied->mitgliedsnummer,
            '{name}' => trim($mitglied->vorname . ' ' . $mitglied->nachname),
        ]);

        return SepaText::bereinigen($text, Pain008::ZWECK_LAENGE);
    }

    /** The template in effect: the club's own or the default. */
    public static function vorlage(Vereinsdaten $verein): string
    {
        $vorlage = trim($verein->wert('verwendungszweck'));

        return $vorlage !== '' ? $vorlage : self::VERWENDUNGSZWECK;
    }

    private function abschliessen(int $id, EinzugStatus $status, ?int $von, \DateTimeImmutable $jetzt): Einzug
    {
        $einzug = Zeilen::transaktion($this->pdo, function () use ($id, $status, $von, $jetzt): Einzug {
            $einzug = $this->einzuege->finde($id) ?? throw new \OutOfBoundsException('No direct debit ' . $id);
            // The conditional update decides: of two clicks at once, one wins.
            if (!$this->einzuege->abschliessen($id, $status, $von, $jetzt)) {
                throw new EinzugNichtMoeglich(sprintf('Der Einzug Nr. %d ist schon %s.', $id, ($this->einzuege->finde($id) ?? $einzug)->status->bezeichnung()));
            }

            $text = $status === EinzugStatus::Bestaetigt
                ? sprintf('Bezahlt per Einzug Nr. %d am %s', $id, Datum::anzeigen($einzug->faelligAm))
                : sprintf('Einzug Nr. %d verworfen, wieder offen', $id);
            foreach ($this->beitraege->desEinzugsZumAendern($id) as $beitrag) {
                if ($beitrag->status !== JahresbeitragStatus::ImEinzug) {
                    continue;
                }
                $vermerk = new Vermerk($von, $jetzt, $text);
                $this->beitraege->speichern($status === EinzugStatus::Bestaetigt
                    ? $beitrag->bezahltPerEinzug($einzug->faelligAm)->mitVermerk($vermerk)
                    : $beitrag->ausEinzugZurueck($vermerk));
            }

            return $this->einzuege->finde($id) ?? $einzug;
        });

        $this->audit->protokollieren(
            $status === EinzugStatus::Bestaetigt ? AuditAktion::EinzugBestaetigt : AuditAktion::EinzugVerworfen,
            $von,
            'einzug',
            $id,
            ['anzahl' => $einzug->anzahl, 'summe_cent' => $einzug->summeCent],
        );

        return $einzug;
    }

    /**
     * Whether and why not an open fee can be collected (08 section 3):
     * Zahlart `lastschrift`, a valid IBAN, and a mandate the file can carry.
     */
    private static function kandidat(Jahresbeitrag $beitrag, Mitglied $mitglied, Vereinsdaten $verein, \DateTimeImmutable $heute): EinzugKandidat
    {
        if ($mitglied->zahlart === Zahlart::Selbst) {
            return new EinzugKandidat($beitrag, $mitglied, 'zahlt selbst (Zahlart „selbst“)', true);
        }
        if ($mitglied->iban === '') {
            return new EinzugKandidat($beitrag, $mitglied, 'keine IBAN', true);
        }
        if (!$mitglied->hatGueltigeIban()) {
            return new EinzugKandidat($beitrag, $mitglied, 'IBAN ungültig (Prüfziffer)', true);
        }
        if (!SepaText::istReferenz(self::mandatsreferenz($mitglied))) {
            return new EinzugKandidat($beitrag, $mitglied, 'Mandatsreferenz unbrauchbar (höchstens 35 Zeichen: A–Z, 0–9, Leerzeichen und / - ? : ( ) . , \' +)');
        }
        $mandatsdatum = self::mandatsdatum($mitglied, $verein);
        if ($mandatsdatum === null) {
            return new EinzugKandidat($beitrag, $mitglied, 'kein Mandatsdatum (weder eigenes noch Eintrittsdatum noch Standard-Mandatsdatum)');
        }
        if ($mandatsdatum->format('Y-m-d') > $heute->format('Y-m-d')) {
            return new EinzugKandidat($beitrag, $mitglied, 'Mandatsdatum liegt in der Zukunft');
        }
        if (self::kontoinhaber($mitglied) === '') {
            return new EinzugKandidat($beitrag, $mitglied, 'Name des Kontoinhabers fehlt');
        }

        return new EinzugKandidat($beitrag, $mitglied);
    }

    /** The transaction of one collectable fee; the EndToEndId names run, member number and year. */
    private static function lastschrift(EinzugKandidat $k, Vereinsdaten $verein, int $einzugId, \DateTimeImmutable $heute): Pain008Lastschrift
    {
        $mandatsdatum = self::mandatsdatum($k->mitglied, $verein) ?? $heute;

        return new Pain008Lastschrift(
            endToEndId: sprintf('E%d-%d-%d', $einzugId, $k->mitglied->mitgliedsnummer, $k->beitrag->jahr),
            cent: $k->beitrag->betragCent,
            mandatsreferenz: self::mandatsreferenz($k->mitglied),
            mandatsdatum: $mandatsdatum,
            name: self::kontoinhaber($k->mitglied),
            iban: Iban::normalisieren($k->mitglied->iban),
            bic: self::bic($k->mitglied->bic),
            verwendungszweck: self::verwendungszweck(self::vorlage($verein), $k->beitrag->jahr, $k->mitglied),
        );
    }

    /**
     * As entered (or the member number) and never rewritten: the debtor's
     * bank matches it against the signed mandate. One that does not fit the
     * file keeps the fee out of the run instead (kandidat()).
     */
    private static function mandatsreferenz(Mitglied $mitglied): string
    {
        return $mitglied->effektiveMandatsreferenz();
    }

    private static function mandatsdatum(Mitglied $mitglied, Vereinsdaten $verein): ?\DateTimeImmutable
    {
        return $mitglied->effektivesMandatsdatum(Mitglied::datum($verein->wert('mandatsdatum')));
    }

    /** Account holder if different, else the member - in the SEPA character set. */
    private static function kontoinhaber(Mitglied $mitglied): string
    {
        return SepaText::bereinigen($mitglied->kontoinhaberName(), Pain008::NAME_LAENGE);
    }

    /** The creditor: account holder, else the club name (11 section 3). */
    private static function glaeubiger(Vereinsdaten $verein): string
    {
        $kontoinhaber = SepaText::bereinigen($verein->wert('kontoinhaber'), Pain008::NAME_LAENGE);

        return $kontoinhaber !== '' ? $kontoinhaber : SepaText::bereinigen($verein->wert('vereinsname'), Pain008::NAME_LAENGE);
    }

    /** A BIC only when it has the right shape - otherwise the bank is found by IBAN. */
    private static function bic(string $bic): string
    {
        $bic = strtoupper((string) preg_replace('/\s+/', '', $bic));

        return Pain008::istBic($bic) ? $bic : '';
    }

    /**
     * Every member by id, former ones included (an open fee may outlive the
     * membership).
     *
     * @return array<int, Mitglied>
     */
    private function mitgliederNachId(): array
    {
        $nachId = [];
        foreach ($this->mitglieder->alle(true) as $mitglied) {
            $nachId[(int) $mitglied->id] = $mitglied;
        }

        return $nachId;
    }

    private static function reihenfolge(EinzugKandidat $a, EinzugKandidat $b): int
    {
        return [mb_strtolower($a->mitglied->nachname), mb_strtolower($a->mitglied->vorname), $a->mitglied->mitgliedsnummer, $a->beitrag->jahr]
            <=> [mb_strtolower($b->mitglied->nachname), mb_strtolower($b->mitglied->vorname), $b->mitglied->mitgliedsnummer, $b->beitrag->jahr];
    }

    private static function jetzt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(Datum::ZEITZONE));
    }
}
