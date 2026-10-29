<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Dienste;
use App\Domain\Permission;
use App\Repository\MitgliedRepository;
use App\Service\Mitglied\HinweisLader;

/**
 * The hints at the top of an opened member (docs/spec/07-mitglieder.md
 * section 6, 06 section 2). Computed on every view, so they are always the
 * current state - also right after a save.
 */
final readonly class HinweiseAbschnitt implements Abschnitt
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
        return 'hinweise';
    }

    public function titel(): string
    {
        return 'Hinweise';
    }

    public function reihenfolge(): int
    {
        return 10;
    }

    public function recht(): Permission
    {
        return Permission::MitgliederSehen;
    }

    public function template(): string
    {
        return 'mitglied/hinweise';
    }

    public function daten(int $mitgliedId): array
    {
        $pdo = $this->dienste->pdo();
        $mitglied = new MitgliedRepository($pdo)->finde($mitgliedId);

        return ['hinweise' => $mitglied === null ? [] : HinweisLader::aus($pdo)->fuer($mitglied)];
    }
}
