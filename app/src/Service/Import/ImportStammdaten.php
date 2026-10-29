<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\Abteilung;
use App\Domain\Beitragsart;
use App\Domain\Funktion;
use App\Repository\AbteilungRepository;
use App\Repository\BeitragsartRepository;
use App\Repository\FunktionRepository;

/**
 * The master data an import.csv refers to (docs/spec/10-import.md section 1):
 * departments by BLSV number, fee types and functions by name. Inactive
 * entries count too - the file is a snapshot of the old software, and what
 * is switched off here is still a valid target.
 */
final readonly class ImportStammdaten
{
    /** @var array<string, Abteilung> by BLSV number without leading zeros */
    private array $abteilungen;

    /** @var array<string, Beitragsart> by lower-case name */
    private array $beitragsarten;

    /** @var array<string, Funktion> by lower-case name */
    private array $funktionen;

    /**
     * @param list<Abteilung> $abteilungen
     * @param list<Beitragsart> $beitragsarten
     * @param list<Funktion> $funktionen
     */
    public function __construct(array $abteilungen, array $beitragsarten, array $funktionen)
    {
        $nachNummer = [];
        foreach ($abteilungen as $abteilung) {
            if ($abteilung->blsvNummer !== '') {
                $nachNummer[self::nummer($abteilung->blsvNummer)] ??= $abteilung;
            }
        }
        $this->abteilungen = $nachNummer;
        $this->beitragsarten = self::nachName($beitragsarten);
        $this->funktionen = self::nachName($funktionen);
    }

    public static function aus(\PDO $pdo): self
    {
        return new self(
            new AbteilungRepository($pdo)->alle(),
            new BeitragsartRepository($pdo)->alle(),
            new FunktionRepository($pdo)->alle(),
        );
    }

    /** "09" and "9" are the same department: Excel drops leading zeros. */
    public function abteilung(string $blsvNummer): ?Abteilung
    {
        return $this->abteilungen[self::nummer($blsvNummer)] ?? null;
    }

    public function beitragsart(string $name): ?Beitragsart
    {
        return $this->beitragsarten[self::schluessel($name)] ?? null;
    }

    public function funktion(string $name): ?Funktion
    {
        return $this->funktionen[self::schluessel($name)] ?? null;
    }

    /**
     * @return list<Beitragsart>
     */
    public function alleBeitragsarten(): array
    {
        return array_values($this->beitragsarten);
    }

    private static function nummer(string $nummer): string
    {
        $nummer = trim($nummer);

        return ctype_digit($nummer) ? (ltrim($nummer, '0') ?: '0') : $nummer;
    }

    private static function schluessel(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @template T of Beitragsart|Funktion
     * @param list<T> $eintraege
     * @return array<string, T>
     */
    private static function nachName(array $eintraege): array
    {
        $nachName = [];
        foreach ($eintraege as $eintrag) {
            $nachName[self::schluessel($eintrag->name)] ??= $eintrag;
        }

        return $nachName;
    }
}
