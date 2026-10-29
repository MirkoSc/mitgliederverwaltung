<?php

declare(strict_types=1);

namespace App\Service\Beitraege;

use App\Domain\BeitragNichtAenderbar;
use App\Domain\Beitragsart;
use App\Domain\Jahresbeitrag;
use App\Domain\Mitglied;
use App\Domain\Vermerk;
use App\Domain\Zahlweise;
use App\Repository\BeitragsartRepository;
use App\Repository\JahresbeitragRepository;
use App\Repository\MitgliedRepository;
use App\Repository\Zeilen;
use App\Support\Datum;
use App\Support\Geld;

/**
 * The annual fees (docs/spec/08-beitraege.md section 2): create them for a
 * year, change the amount while open, paid by hand, returned debit, notes.
 * Admin only - the routes check BeitraegeVerwalten.
 *
 * Every change leaves a note (who, when, what); the status rules live in
 * App\Domain\Jahresbeitrag.
 */
final readonly class Jahresbeitraege
{
    /** Years the page offers - wide enough for old open fees and the next year. */
    public const int ERSTES_JAHR = 2000;

    public function __construct(
        private \PDO $pdo,
        private JahresbeitragRepository $beitraege,
        private MitgliedRepository $mitglieder,
        private BeitragsartRepository $beitragsarten,
    ) {
    }

    public static function mit(\PDO $pdo): self
    {
        return new self($pdo, new JahresbeitragRepository($pdo), new MitgliedRepository($pdo), new BeitragsartRepository($pdo));
    }

    public static function letztesJahr(\DateTimeImmutable $heute): int
    {
        return (int) $heute->format('Y') + 1;
    }

    /**
     * The proposed amount (08 section 2): joined in that year => pro rata by
     * begun months, `betrag × (13 − Eintrittsmonat) / 12`, rounded half up to
     * the cent; joined before or unknown => the full amount. Integer
     * arithmetic only.
     */
    public static function anteiligerBetrag(int $betragCent, ?\DateTimeImmutable $eintritt, int $jahr): int
    {
        if ($eintritt === null || (int) $eintritt->format('Y') !== $jahr) {
            return $betragCent;
        }

        return self::gerundet($betragCent * self::monate($eintritt), 12);
    }

    /**
     * "Beiträge für <Jahr> erzeugen": the missing fee of every member that
     * is not former on 1 January (the fee's key date), has a fee type with an
     * amount above 0 and did not join after that year. Pressing it again
     * only adds what is missing - new members of the year, for instance.
     */
    public function erzeugen(int $jahr, ?int $von, ?\DateTimeImmutable $jetzt = null): ErzeugenErgebnis
    {
        $jetzt ??= new \DateTimeImmutable('now', new \DateTimeZone(Datum::ZEITZONE));
        $stichtag = new \DateTimeImmutable(sprintf('%04d-01-01', $jahr), new \DateTimeZone(Datum::ZEITZONE));

        $arten = [];
        foreach ($this->beitragsarten->alle() as $art) {
            $arten[(int) $art->id] = $art;
        }

        return Zeilen::transaktion($this->pdo, function () use ($jahr, $von, $jetzt, $stichtag, $arten): ErzeugenErgebnis {
            $neu = 0;
            $vorhanden = 0;
            foreach ($this->mitglieder->alle(true) as $mitglied) {
                $art = $mitglied->beitragsartId === null ? null : ($arten[$mitglied->beitragsartId] ?? null);
                $beitrag = self::fuer($mitglied, $art, $jahr, $stichtag, $von, $jetzt);
                if ($beitrag === null) {
                    continue;
                }
                $this->beitraege->anlegenWennFehlt($beitrag) === null ? $vorhanden++ : $neu++;
            }

            return new ErzeugenErgebnis($jahr, $neu, $vorhanden);
        });
    }

    /** New amount while the fee is open; the reason is optional. */
    public function betragAendern(int $id, int $betragCent, string $grund, ?int $von): Jahresbeitrag
    {
        return $this->aendern($id, static function (Jahresbeitrag $b) use ($betragCent, $grund, $von): Jahresbeitrag {
            $text = sprintf('Betrag von %s auf %s geändert', Geld::anzeigen($b->betragCent), Geld::anzeigen($betragCent));

            return $b->mitBetrag($betragCent, self::vermerk($von, self::mitZusatz($text, $grund)));
        });
    }

    public function bezahltVonHand(int $id, Zahlweise $zahlweise, \DateTimeImmutable $am, string $text, ?int $von): Jahresbeitrag
    {
        return $this->aendern($id, static function (Jahresbeitrag $b) use ($zahlweise, $am, $text, $von): Jahresbeitrag {
            $was = sprintf('Bezahlt (%s) am %s', $zahlweise->bezeichnung(), Datum::anzeigen($am));

            return $b->bezahltVonHand($zahlweise, $am, self::vermerk($von, self::mitZusatz($was, $text)));
        });
    }

    /** Back to open; the note is required (08 section 2). */
    public function ruecklastschrift(int $id, string $text, ?int $von): Jahresbeitrag
    {
        if (trim($text) === '') {
            throw new BeitragNichtAenderbar('Bitte schreiben Sie in den Vermerk, warum der Beitrag wieder offen ist.');
        }

        return $this->aendern($id, static function (Jahresbeitrag $b) use ($text, $von): Jahresbeitrag {
            $was = $b->einzugId !== null
                ? sprintf('Rücklastschrift aus Einzug Nr. %d', $b->einzugId)
                : 'Zurück auf offen';

            return $b->ruecklastschrift(self::vermerk($von, self::mitZusatz($was, $text)));
        });
    }

    /** A note of its own, in any status. */
    public function vermerken(int $id, string $text, ?int $von): Jahresbeitrag
    {
        if (trim($text) === '') {
            throw new BeitragNichtAenderbar('Der Vermerk ist leer.');
        }

        return $this->aendern($id, static fn(Jahresbeitrag $b): Jahresbeitrag => $b->mitVermerk(self::vermerk($von, trim($text))));
    }

    /**
     * Loads, changes and saves one fee in a transaction, the row locked so
     * two changes at the same moment cannot overwrite each other's note.
     *
     * @param \Closure(Jahresbeitrag): Jahresbeitrag $aenderung
     * @throws \OutOfBoundsException no such fee
     * @throws BeitragNichtAenderbar
     */
    private function aendern(int $id, \Closure $aenderung): Jahresbeitrag
    {
        return Zeilen::transaktion($this->pdo, function () use ($id, $aenderung): Jahresbeitrag {
            $beitrag = $this->beitraege->findeZumAendern($id) ?? throw new \OutOfBoundsException('No annual fee ' . $id);
            $neu = $aenderung($beitrag);
            $this->beitraege->speichern($neu);

            return $neu;
        });
    }

    private static function fuer(
        Mitglied $mitglied,
        ?Beitragsart $art,
        int $jahr,
        \DateTimeImmutable $stichtag,
        ?int $von,
        \DateTimeImmutable $jetzt,
    ): ?Jahresbeitrag {
        if ($art === null || $art->betragCent <= 0 || $mitglied->istEhemalig($stichtag)) {
            return null;
        }
        if ($mitglied->eintrittsdatum !== null && (int) $mitglied->eintrittsdatum->format('Y') > $jahr) {
            return null;
        }

        $betrag = self::anteiligerBetrag($art->betragCent, $mitglied->eintrittsdatum, $jahr);
        if ($betrag <= 0) {
            return null;
        }

        $text = sprintf('Erzeugt aus Beitragsart „%s“ (%s)', $art->name, Geld::anzeigen($art->betragCent));
        if ($betrag !== $art->betragCent && $mitglied->eintrittsdatum !== null) {
            $text .= sprintf(', anteilig %d/12 wegen Eintritt im %s', self::monate($mitglied->eintrittsdatum), $mitglied->eintrittsdatum->format('m/Y'));
        }

        return new Jahresbeitrag(
            mitgliedId: (int) $mitglied->id,
            jahr: $jahr,
            betragCent: $betrag,
            vermerke: [new Vermerk($von, $jetzt, $text)],
        );
    }

    /** Begun months from the month of entry to December: May => 8. */
    private static function monate(\DateTimeImmutable $eintritt): int
    {
        return 13 - (int) $eintritt->format('n');
    }

    /** $zaehler / $nenner rounded half up (kaufmännisch), for amounts >= 0. */
    private static function gerundet(int $zaehler, int $nenner): int
    {
        return intdiv(2 * $zaehler + $nenner, 2 * $nenner);
    }

    private static function vermerk(?int $von, string $text): Vermerk
    {
        return new Vermerk($von, new \DateTimeImmutable('now', new \DateTimeZone(Datum::ZEITZONE)), $text);
    }

    private static function mitZusatz(string $was, string $zusatz): string
    {
        $zusatz = trim($zusatz);

        return $zusatz === '' ? $was : $was . ': ' . $zusatz;
    }
}
