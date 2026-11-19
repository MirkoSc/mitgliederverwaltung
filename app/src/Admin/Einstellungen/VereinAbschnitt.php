<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Service\Verein\LogoService;
use App\Service\Verein\VereinRepository;
use App\Service\Verein\Vereinsdaten;

/**
 * Settings section Verein (docs/spec/11-vereinsdaten.md): club data,
 * associations, logo. The forms post to app/routes/verein.php. Admin and Verwaltung (admin.verein);
 * export and import of the club data only for the admin (admin.system).
 */
final readonly class VereinAbschnitt implements Abschnitt
{
    public function __construct(private VereinRepository $verein, private LogoService $logo, private bool $austauschen)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(VereinRepository::aus($dienste), LogoService::aus($dienste), $dienste->view->konto()?->darf(Permission::AdminSystem) === true);
    }

    public function schluessel(): string
    {
        return 'verein';
    }

    public function titel(): string
    {
        return 'Verein';
    }

    public function reihenfolge(): int
    {
        return 10;
    }

    public function recht(): Permission
    {
        return Permission::AdminVerein;
    }

    public function template(): string
    {
        return 'admin/einstellungen/verein';
    }

    public function daten(): array
    {
        return [
            'verein' => $this->verein->get(),
            'felder' => Vereinsdaten::FELDER,
            'verbaende' => $this->verein->verbaende(),
            'logoVersion' => $this->logo->version(),
            'austauschen' => $this->austauschen,
        ];
    }
}
