<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Mitglied;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\HinweisKontext;
use App\Service\Mitglied\Hinweise;

/**
 * The preview of an import.csv (docs/spec/10-import.md section 1): how many
 * members, families and departments, the lines with errors and the lines
 * with hints (07 section 6). Computed from the file, nothing is stored.
 *
 * The hints are those of the members as they will be saved, the family of
 * the file standing in for the family that does not exist yet. Hints that
 * rest on proofs are left out: proofs are not imported, so "Rentennachweis
 * fehlt" would be reported for every pensioner and bury the rest.
 */
final readonly class ImportVorschau
{
    /** Hints that need a proof, which the import never brings (see above). */
    private const array OHNE_NACHWEIS = [
        HinweisArt::RentennachweisFehlt,
        HinweisArt::JugendbeitragAb18,
        HinweisArt::NachweisAbgelaufen,
    ];

    /**
     * @param list<ImportFehler> $fehler
     * @param list<array{zeile: int, nummer: int, name: string, texte: list<string>}> $hinweise lines with hints
     * @param array<string, int> $hinweiseNachArt hint name => number of members
     * @param array<string, int> $abteilungen department name => number of members
     * @param list<string> $unbekannteSpalten
     */
    public function __construct(
        public int $mitglieder,
        public int $familien,
        public array $abteilungen,
        public array $fehler,
        public array $hinweise,
        public array $hinweiseNachArt,
        public array $unbekannteSpalten,
    ) {
    }

    /**
     * @param array<int, string> $abteilungsnamen department id => name
     */
    public static function aus(
        ImportDatei $datei,
        ImportStammdaten $stammdaten,
        array $abteilungsnamen,
        \DateTimeImmutable $stichtag,
    ): self {
        // Stand-ins for what the database will give: the line number as id,
        // a running number per family key as family id.
        $familienIds = [];
        $personen = [];
        foreach ($datei->eintraege as $eintrag) {
            $familieId = null;
            if ($eintrag->familie !== null) {
                $familieId = $familienIds[$eintrag->familie] ??= count($familienIds) + 1;
            }
            $personen[$eintrag->zeile] = $eintrag->mitglied->mit(['id' => $eintrag->zeile, 'familieId' => $familieId]);
        }
        $nachFamilie = [];
        foreach ($personen as $person) {
            if ($person->familieId !== null) {
                $nachFamilie[$person->familieId][] = $person;
            }
        }

        $hinweise = [];
        $nachArt = [];
        $nachAbteilung = [];
        foreach ($datei->eintraege as $eintrag) {
            $person = $personen[$eintrag->zeile];
            foreach ($person->abteilungIds as $id) {
                $name = $abteilungsnamen[$id] ?? '?';
                $nachAbteilung[$name] = ($nachAbteilung[$name] ?? 0) + 1;
            }

            $kontext = new HinweisKontext(
                $stichtag,
                $person->familieId === null ? [] : $nachFamilie[$person->familieId],
                [],
                $stammdaten->alleBeitragsarten(),
            );
            $texte = [];
            foreach (Hinweise::fuer($person, $kontext) as $hinweis) {
                if (in_array($hinweis->art, self::OHNE_NACHWEIS, true)) {
                    continue;
                }
                $texte[] = $hinweis->text;
                $nachArt[$hinweis->art->bezeichnung()] = ($nachArt[$hinweis->art->bezeichnung()] ?? 0) + 1;
            }
            if ($texte !== []) {
                $hinweise[] = [
                    'zeile' => $eintrag->zeile,
                    'nummer' => $person->mitgliedsnummer,
                    'name' => self::name($person),
                    'texte' => $texte,
                ];
            }
        }
        ksort($nachAbteilung);
        arsort($nachArt);

        return new self(
            count($datei->eintraege),
            count($familienIds),
            $nachAbteilung,
            $datei->fehler,
            $hinweise,
            $nachArt,
            $datei->unbekannteSpalten,
        );
    }

    public function istFehlerfrei(): bool
    {
        return $this->fehler === [];
    }

    private static function name(Mitglied $m): string
    {
        return trim($m->vorname . ' ' . $m->nachname);
    }
}
