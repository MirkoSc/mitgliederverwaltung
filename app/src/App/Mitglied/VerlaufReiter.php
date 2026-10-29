<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Dienste;
use App\Domain\Permission;
use App\Repository\BenutzerRepository;
use App\Repository\MitgliedRepository;

/**
 * The tab "Verlauf" of an opened member (docs/spec/07-mitglieder.md
 * section 7): the versions, newest first, each with the changed fields as
 * "alt -> neu" and a button "Zurückholen" (App\App\VerlaufController).
 */
final readonly class VerlaufReiter implements Reiter
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
        return 'verlauf';
    }

    public function titel(): string
    {
        return 'Verlauf';
    }

    public function reihenfolge(): int
    {
        return 30;
    }

    public function recht(): Permission
    {
        return Permission::MitgliederSehen;
    }

    public function template(): string
    {
        return 'mitglied/verlauf';
    }

    public function daten(int $mitgliedId): array
    {
        $versionen = new MitgliedRepository($this->dienste->pdo())->versionen($mitgliedId);
        $konto = $this->dienste->view->konto();
        $benutzer = new BenutzerRepository($this->dienste->pdo());

        $eintraege = [];
        $namen = [];
        foreach ($versionen as $i => $version) {
            $von = $version->geaendertVon;
            if ($von !== null && !array_key_exists($von, $namen)) {
                $namen[$von] = $benutzer->findById($von)?->name;
            }
            $eintraege[] = [
                'version' => $version,
                'aenderungen' => VerlaufAenderungen::zwischen($versionen[$i + 1] ?? null, $version),
                'wer' => $von === null ? null : $namen[$von],
                'aktuell' => $i === 0,
            ];
        }

        return [
            'mitgliedId' => $mitgliedId,
            'eintraege' => $eintraege,
            'darfZurueckholen' => $konto !== null && $konto->darf(Permission::MitgliederBearbeiten),
        ];
    }
}
