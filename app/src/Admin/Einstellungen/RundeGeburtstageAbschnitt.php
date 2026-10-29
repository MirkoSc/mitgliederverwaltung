<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Repository\SettingRepository;

/**
 * Settings section runde Geburtstage (docs/spec/09-listen-und-exporte.md section 3): a list of ages. The forms post to app/routes/stammdaten.php. Admin only.
 */
final readonly class RundeGeburtstageAbschnitt implements Abschnitt
{
    public function __construct(private SettingRepository $einstellungen)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(new SettingRepository($dienste->pdo()));
    }

    public function schluessel(): string
    {
        return 'geburtstage';
    }

    public function titel(): string
    {
        return 'Runde Geburtstage';
    }

    public function reihenfolge(): int
    {
        return 50;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'admin/einstellungen/geburtstage';
    }

    public function daten(): array
    {
        return [
            'werte' => implode(', ', array_filter((array) $this->einstellungen->get('runde_geburtstage'), 'is_int')),
        ];
    }
}
