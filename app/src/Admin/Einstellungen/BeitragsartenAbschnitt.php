<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Domain\AktivArt;
use App\Domain\BeitragsRegel;
use App\Repository\BeitragsartRepository;

/**
 * Settings section Beitragsarten (docs/spec/08-beitraege.md section 1): amount, rule, active kind. The forms post to app/routes/stammdaten.php. Admin only.
 */
final readonly class BeitragsartenAbschnitt implements Abschnitt
{
    public function __construct(private BeitragsartRepository $beitragsarten)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(new BeitragsartRepository($dienste->pdo()));
    }

    public function schluessel(): string
    {
        return 'beitragsarten';
    }

    public function titel(): string
    {
        return 'Beitragsarten';
    }

    public function reihenfolge(): int
    {
        return 40;
    }

    public function recht(): Permission
    {
        return Permission::AdminEinstellungen;
    }

    public function template(): string
    {
        return 'admin/einstellungen/beitragsarten';
    }

    public function daten(): array
    {
        return [
            'beitragsarten' => $this->beitragsarten->alle(),
            'regeln' => BeitragsRegel::cases(),
            'aktivArten' => AktivArt::cases(),
        ];
    }
}
