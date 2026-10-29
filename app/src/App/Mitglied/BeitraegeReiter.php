<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\App\BeitraegeController;
use App\App\MitgliedController;
use App\Dienste;
use App\Domain\Permission;
use App\Repository\BenutzerRepository;
use App\Repository\JahresbeitragRepository;

/**
 * The tab "Beiträge" of an opened member (docs/spec/08-beitraege.md
 * section 2): all years with amount, status and notes, newest first; each
 * year opens the page of that fee. Admin only - the Vorstand never sees
 * the tab, and every route behind it checks BeitraegeVerwalten itself.
 */
final readonly class BeitraegeReiter implements Reiter
{
    public function __construct(private Dienste $dienste)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self($dienste);
    }

    public function schluessel(): string
    {
        return 'beitraege';
    }

    public function titel(): string
    {
        return 'Beiträge';
    }

    public function reihenfolge(): int
    {
        return 20;
    }

    public function recht(): Permission
    {
        return Permission::BeitraegeVerwalten;
    }

    public function template(): string
    {
        return 'mitglied/beitraege';
    }

    public function daten(int $mitgliedId): array
    {
        $beitraege = new JahresbeitragRepository($this->dienste->pdo())->fuerMitglied($mitgliedId);
        $benutzer = new BenutzerRepository($this->dienste->pdo());

        $namen = [];
        foreach ($beitraege as $beitrag) {
            foreach ($beitrag->vermerke as $vermerk) {
                if ($vermerk->von !== null && !array_key_exists($vermerk->von, $namen)) {
                    $namen[$vermerk->von] = $benutzer->findById($vermerk->von)?->name;
                }
            }
        }

        return [
            'beitraege' => $beitraege,
            'namen' => $namen,
            'zurueck' => MitgliedController::adresse($mitgliedId, $this->schluessel()),
            'seitenAdresse' => BeitraegeController::seitenAdresse((int) new \DateTimeImmutable('today')->format('Y')),
        ];
    }
}
