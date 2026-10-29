<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Dienste;
use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\Funktion;
use App\Domain\Mitglied;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FunktionRepository;
use App\Service\Verein\VereinRepository;

/**
 * What the member form offers to choose from (docs/spec/07-mitglieder.md
 * sections 1, 2 and 4): departments, functions and fee types, plus the
 * club's default mandate date (11) for the hint below "Mandatsdatum".
 *
 * Loaded once per request; inactive entries stay in here, because a member
 * who already has one keeps it (the form shows it marked as no longer
 * active, 07 section 2).
 */
final readonly class Auswahllisten
{
    /**
     * @param list<Abteilung> $abteilungen
     * @param list<Funktion> $funktionen
     * @param list<Beitragsart> $beitragsarten
     */
    public function __construct(
        public array $abteilungen,
        public array $funktionen,
        public array $beitragsarten,
        public ?\DateTimeImmutable $vereinsMandatsdatum = null,
    ) {
    }

    public static function aus(Dienste $dienste): self
    {
        return $dienste->dienst(self::class, static function (Dienste $d): self {
            $pdo = $d->pdo();

            return new self(
                new AbteilungRepository($pdo)->alle(),
                new FunktionRepository($pdo)->alle(),
                new BeitragsartRepository($pdo)->alle(),
                Mitglied::datum(new VereinRepository($pdo, $d->einstellungen())->get()->wert('mandatsdatum')),
            );
        });
    }

    /**
     * The departments the form shows: the active ones and those the member
     * already has.
     *
     * @param list<int> $gewaehlt
     * @return list<Abteilung>
     */
    public function abteilungenFuer(array $gewaehlt): array
    {
        return self::sichtbar($this->abteilungen, $gewaehlt);
    }

    /**
     * @param list<int> $gewaehlt
     * @return list<Funktion>
     */
    public function funktionenFuer(array $gewaehlt): array
    {
        return self::sichtbar($this->funktionen, $gewaehlt);
    }

    /**
     * @return list<Beitragsart>
     */
    public function beitragsartenFuer(?int $gewaehlt): array
    {
        return self::sichtbar($this->beitragsarten, $gewaehlt === null ? [] : [$gewaehlt]);
    }

    /**
     * Names of the functions that come with a licence number ("Übungsleiter").
     *
     * @return list<string>
     */
    public function funktionenMitLizenz(): array
    {
        $namen = [];
        foreach ($this->funktionen as $funktion) {
            if ($funktion->mitLizenz && $funktion->aktiv) {
                $namen[] = $funktion->name;
            }
        }

        return $namen;
    }

    /**
     * Which of the posted ids exist - what is not in the lists any more
     * (deleted meanwhile, or never existed) is dropped.
     *
     * @param list<int> $ids
     * @param list<Abteilung>|list<Funktion>|list<Beitragsart> $liste
     * @return list<int>
     */
    public static function vorhandene(array $ids, array $liste): array
    {
        $bekannt = array_map(static fn(Abteilung|Funktion|Beitragsart $eintrag): ?int => $eintrag->id, $liste);

        return array_values(array_filter($ids, static fn(int $id): bool => in_array($id, $bekannt, true)));
    }

    /**
     * @template T of Abteilung|Funktion|Beitragsart
     * @param list<T> $liste
     * @param list<int> $gewaehlt
     * @return list<T>
     */
    private static function sichtbar(array $liste, array $gewaehlt): array
    {
        return array_values(array_filter(
            $liste,
            static fn(Abteilung|Funktion|Beitragsart $eintrag): bool => $eintrag->aktiv || in_array($eintrag->id, $gewaehlt, true),
        ));
    }
}
