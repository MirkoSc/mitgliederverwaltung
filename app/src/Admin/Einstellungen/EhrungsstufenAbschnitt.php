<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Repository\SettingRepository;

/**
 * Settings section Ehrungsstufen (docs/spec/09-listen-und-exporte.md section 3): a list of years of membership. The forms post to app/routes/stammdaten.php. Admin only.
 */
final readonly class EhrungsstufenAbschnitt implements Abschnitt
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
        return 'ehrungsstufen';
    }

    public function titel(): string
    {
        return 'Ehrungsstufen';
    }

    public function reihenfolge(): int
    {
        return 60;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'admin/einstellungen/ehrungsstufen';
    }

    public function daten(): array
    {
        return [
            'werte' => implode(', ', array_filter((array) $this->einstellungen->get('ehrungsstufen'), 'is_int')),
        ];
    }
}
