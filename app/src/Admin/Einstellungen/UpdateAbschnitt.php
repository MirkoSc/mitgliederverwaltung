<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Service\Update\UpdateService;

/**
 * Settings section Update, part of "System" (docs/spec/04-installation-und-
 * update.md section 3): channel, search, run the step chain, roll back. The
 * forms post to app/routes/update.php; the chain runs in public/js/update.js.
 */
final readonly class UpdateAbschnitt implements Abschnitt
{
    public function __construct(
        private UpdateService $updates,
        private string $version,
    ) {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(UpdateService::aus($dienste), $dienste->version->value);
    }

    public function schluessel(): string
    {
        return 'update';
    }

    public function titel(): string
    {
        return 'Update';
    }

    public function reihenfolge(): int
    {
        return 85;
    }

    public function recht(): Permission
    {
        return Permission::AdminSystem;
    }

    public function template(): string
    {
        return 'admin/einstellungen/update';
    }

    public function daten(): array
    {
        return [
            'installiert' => $this->version,
            'kanal' => $this->updates->channel(),
            'stand' => $this->updates->state(),
            'schritte' => UpdateService::STEPS,
            'zurueckMoeglich' => $this->updates->kannZurueckrollen(),
            // The settings page renders sections as fragments without a
            // script list; the section brings its own (external) script.
            'version' => $this->version,
        ];
    }
}
