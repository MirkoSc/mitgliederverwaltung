<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Dienste;
use App\Domain\FamilienRolle;
use App\Domain\Permission;
use App\Repository\FamilieRepository;
use App\Repository\MitgliedRepository;

/**
 * The tab "Familie" of an opened member (docs/spec/07-mitglieder.md
 * section 3, issue #20): the family and the role, the other persons of the
 * family, and the ways to join, create or leave one. The forms post to the
 * routes of app/routes/familie.php.
 */
final readonly class FamilieReiter implements Reiter
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
        return 'familie';
    }

    public function titel(): string
    {
        return 'Familie';
    }

    /** Right after "Daten" (Mirko, 29.10.2026). */
    public function reihenfolge(): int
    {
        return 15;
    }

    public function recht(): Permission
    {
        return Permission::MitgliederSehen;
    }

    public function template(): string
    {
        return 'mitglied/familie';
    }

    public function daten(int $mitgliedId): array
    {
        $pdo = $this->dienste->pdo();
        $mitglied = new MitgliedRepository($pdo)->finde($mitgliedId);
        $familie = $mitglied?->familieId === null ? null : new FamilieRepository($pdo)->finde($mitglied->familieId);
        $andere = $familie === null
            ? []
            : array_values(array_filter(
                new MitgliedRepository($pdo)->derFamilie($familie->id),
                static fn($m): bool => $m->id !== $mitgliedId,
            ));
        $konto = $this->dienste->view->konto();

        return [
            'mitglied' => $mitglied,
            'familie' => $familie,
            'andere' => $andere,
            'rollen' => FamilienRolle::cases(),
            'darfPflegen' => $konto !== null && $konto->darf(Permission::MitgliederBearbeiten),
        ];
    }
}
