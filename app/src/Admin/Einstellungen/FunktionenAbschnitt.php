<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Repository\FunktionRepository;

/**
 * Settings section Funktionen (docs/spec/07-mitglieder.md section 4): name, licence flag, order, active. The forms post to app/routes/stammdaten.php. Admin only.
 */
final readonly class FunktionenAbschnitt implements Abschnitt
{
    public function __construct(private FunktionRepository $funktionen)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(new FunktionRepository($dienste->pdo()));
    }

    public function schluessel(): string
    {
        return 'funktionen';
    }

    public function titel(): string
    {
        return 'Funktionen';
    }

    public function reihenfolge(): int
    {
        return 30;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'admin/einstellungen/funktionen';
    }

    public function daten(): array
    {
        return [
            'funktionen' => $this->funktionen->alle(),
        ];
    }
}
