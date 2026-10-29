<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Herkunft;
use App\Domain\Mitglied;
use App\Support\Iban;

/**
 * The sorting of a reconciliation (docs/spec/10-import.md section 4): the
 * lines of a later import.csv against the members in the application,
 * matched by member number.
 *
 *  - neu: in the file, not in the application;
 *  - fehlt: imported here (origin `import`), not in the file, not yet left;
 *  - geaendert: in both, with different values;
 *  - unveraendert: in both, nothing differs (only counted).
 *
 * Pure: no database. What the comparison needs from it - all members, the
 * families by their key of the old software, the members with annual fees -
 * the caller hands in.
 */
final readonly class AbgleichVergleich
{
    /** Marks the family of a line whose key does not exist yet: it will be created. */
    public const string NEUE_FAMILIE = 'neu:';

    /** The column of import.csv behind a field of Mitglied::daten() where the names differ. */
    private const array SPALTE = [
        'beitragsart_id' => 'beitragsart',
        'familie_id' => 'familie',
    ];

    /**
     * @param list<ImportEintrag> $neu by member number
     * @param list<AbgleichFehlt> $fehlt by member number
     * @param list<AbgleichAenderung> $geaendert by member number
     */
    public function __construct(
        public array $neu,
        public array $fehlt,
        public array $geaendert,
        public int $unveraendert,
    ) {
    }

    /**
     * @param list<Mitglied> $bestand all members, former ones included
     * @param array<string, int> $familien id of the family per key of the old software (`familie.import_schluessel`)
     * @param array<int, true> $mitBeitrag ids of the members with at least one annual fee
     */
    public static function aus(ImportDatei $datei, array $bestand, array $familien, array $mitBeitrag): self
    {
        $nachNummer = [];
        foreach ($bestand as $mitglied) {
            $nachNummer[$mitglied->mitgliedsnummer] = $mitglied;
        }

        $neu = [];
        $geaendert = [];
        $unveraendert = 0;
        $inDatei = [];
        foreach ($datei->eintraege as $eintrag) {
            $nummer = $eintrag->mitglied->mitgliedsnummer;
            $inDatei[$nummer] = true;
            $vorhanden = $nachNummer[$nummer] ?? null;
            if ($vorhanden === null) {
                $neu[$nummer] = $eintrag;
                continue;
            }
            $felder = self::unterschiede($vorhanden->daten(), self::zielDaten($eintrag, self::familieDerZeile($eintrag, $familien), $vorhanden, $datei->spalten));
            if ($felder === []) {
                $unveraendert++;
            } else {
                $geaendert[$nummer] = new AbgleichAenderung($vorhanden, $eintrag, $felder);
            }
        }

        $fehlt = [];
        foreach ($nachNummer as $nummer => $mitglied) {
            if (isset($inDatei[$nummer]) || $mitglied->herkunft !== Herkunft::Import || $mitglied->austrittsdatum !== null) {
                continue;
            }
            $fehlt[$nummer] = new AbgleichFehlt($mitglied, !isset($mitBeitrag[(int) $mitglied->id]));
        }

        ksort($neu);
        ksort($fehlt);
        ksort($geaendert);

        return new self(array_values($neu), array_values($fehlt), array_values($geaendert), $unveraendert);
    }

    /** Whether there is anything to take over at all. */
    public function istLeer(): bool
    {
        return $this->neu === [] && $this->fehlt === [] && $this->geaendert === [];
    }

    /**
     * The state a line of the file asks for, in the shape of
     * Mitglied::daten() - what a member is compared with and, through
     * Mitglied::ausDaten(), saved as. The IBAN and BIC are normalised as the
     * repository stores them, so a different spelling is no change. A
     * column the file does not have at all says nothing: the member keeps
     * that value (with $spalten [] every column counts as present).
     *
     * @param int|string|null $familie the family's id; NEUE_FAMILIE . key for one not created yet
     * @param list<string> $spalten the columns of the format the file has (ImportDatei::$spalten)
     * @return array<string, mixed>
     */
    public static function zielDaten(ImportEintrag $eintrag, int|string|null $familie, ?Mitglied $vorhanden = null, array $spalten = []): array
    {
        $daten = [
            ...$eintrag->mitglied->daten(),
            'iban' => Iban::normalisieren($eintrag->mitglied->iban),
            'bic' => strtoupper((string) preg_replace('/\s+/u', '', $eintrag->mitglied->bic)),
            'familie_id' => $familie,
        ];
        if ($vorhanden === null || $spalten === []) {
            return $daten;
        }
        $bisher = $vorhanden->daten();
        $vorhandeneSpalten = array_flip($spalten);
        foreach (array_keys($daten) as $feld) {
            if (!isset($vorhandeneSpalten[self::SPALTE[$feld] ?? $feld])) {
                $daten[$feld] = $bisher[$feld] ?? null;
            }
        }

        return $daten;
    }

    /**
     * The family a line belongs to: none, an existing one by its key, or one
     * still to be created (NEUE_FAMILIE . key).
     *
     * @param array<string, int> $familien
     */
    public static function familieDerZeile(ImportEintrag $eintrag, array $familien): int|string|null
    {
        if ($eintrag->familie === null) {
            return null;
        }

        return $familien[$eintrag->familie] ?? self::NEUE_FAMILIE . $eintrag->familie;
    }

    /**
     * The fields that differ, as field => [in the application, in the file].
     *
     * @param array<string, mixed> $alt
     * @param array<string, mixed> $neu
     * @return array<string, array{mixed, mixed}>
     */
    private static function unterschiede(array $alt, array $neu): array
    {
        $felder = [];
        foreach ($neu as $feld => $wert) {
            if (($alt[$feld] ?? null) !== $wert) {
                $felder[$feld] = [$alt[$feld] ?? null, $wert];
            }
        }

        return $felder;
    }
}
