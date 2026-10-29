<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Repository\AbteilungRepository;

/**
 * Settings section Abteilungen (docs/spec/07-mitglieder.md section 2): name, BLSV number, order, active. The forms post to app/routes/stammdaten.php. Admin only.
 */
final readonly class AbteilungenAbschnitt implements Abschnitt
{
    public function __construct(private AbteilungRepository $abteilungen)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(new AbteilungRepository($dienste->pdo()));
    }

    public function schluessel(): string
    {
        return 'abteilungen';
    }

    public function titel(): string
    {
        return 'Abteilungen';
    }

    public function reihenfolge(): int
    {
        return 20;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'admin/einstellungen/abteilungen';
    }

    public function daten(): array
    {
        return [
            'abteilungen' => $this->abteilungen->alle(),
        ];
    }
}
