<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\BenutzerStatus;
use App\Domain\Permission;
use App\Domain\Rolle;
use App\Repository\BenutzerRepository;
use App\Service\Account\Einladung;

/**
 * Settings section Benutzer (docs/spec/02-sicherheit.md section 5,
 * docs/spec/06-oberflaeche.md section 2): who has an account, with which
 * role and status; invite, change the role, lock, delete. The forms post to
 * app/routes/benutzer.php.
 */
final readonly class BenutzerAbschnitt implements Abschnitt
{
    public function __construct(
        private BenutzerRepository $benutzer,
        private Einladung $einladung,
        private ?int $selbst,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(new BenutzerRepository($dienste->pdo()), Einladung::aus($dienste), $dienste->view->konto()?->id);
    }

    public function schluessel(): string
    {
        return 'benutzer';
    }

    public function titel(): string
    {
        return 'Benutzer';
    }

    public function reihenfolge(): int
    {
        return 70;
    }

    public function recht(): Permission
    {
        return Permission::AdminBenutzer;
    }

    public function template(): string
    {
        return 'admin/einstellungen/benutzer';
    }

    public function daten(): array
    {
        $jetzt = new \DateTimeImmutable();
        $zeilen = [];
        foreach ($this->benutzer->alle() as $benutzer) {
            $zeilen[] = [
                'benutzer' => $benutzer,
                'selbst' => $benutzer->id === $this->selbst,
                'einladungBis' => $benutzer->status === BenutzerStatus::Eingeladen
                    ? $this->einladung->gueltigBis($benutzer->id, $jetzt)
                    : null,
            ];
        }

        return [
            'zeilen' => $zeilen,
            'rollen' => Rolle::cases(),
            'gueltigStunden' => intdiv(Einladung::GUELTIG_SEKUNDEN, 3600),
        ];
    }
}
