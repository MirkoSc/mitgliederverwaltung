<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Dienste;
use App\Domain\NachweisArt;
use App\Domain\Permission;
use App\Repository\BenutzerRepository;
use App\Repository\NachweisRepository;

/**
 * The tab "Nachweise" of an opened member (docs/spec/07-mitglieder.md
 * section 5, issue #23): the proofs with their file, a form to note a new
 * one, and - for the admin only - the delete button. The forms post to the
 * routes of app/routes/nachweise.php.
 */
final readonly class NachweiseReiter implements Reiter
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
        return 'nachweise';
    }

    public function titel(): string
    {
        return 'Nachweise';
    }

    /** Right after "Beiträge" (Mirko, 29.10.2026). */
    public function reihenfolge(): int
    {
        return 25;
    }

    public function recht(): Permission
    {
        return Permission::MitgliederSehen;
    }

    public function template(): string
    {
        return 'mitglied/nachweise';
    }

    public function daten(int $mitgliedId): array
    {
        $pdo = $this->dienste->pdo();
        $nachweise = new NachweisRepository($pdo)->fuerMitglied($mitgliedId);
        $benutzer = new BenutzerRepository($pdo);
        $namen = [];
        foreach ($nachweise as $nachweis) {
            if ($nachweis->vermerktVon !== null && !isset($namen[$nachweis->vermerktVon])) {
                $namen[$nachweis->vermerktVon] = $benutzer->findById($nachweis->vermerktVon)?->name;
            }
        }
        $konto = $this->dienste->view->konto();

        return [
            'mitgliedId' => $mitgliedId,
            'nachweise' => $nachweise,
            'namen' => $namen,
            'arten' => NachweisArt::cases(),
            'heute' => new \DateTimeImmutable('today'),
            'darfPflegen' => $konto !== null && $konto->darf(Permission::MitgliederBearbeiten),
            'darfLoeschen' => $konto !== null && $konto->darf(Permission::MitgliederLoeschen),
        ];
    }
}
