<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\App\MitgliedController;
use App\Dienste;
use App\Domain\Permission;
use App\Repository\GeoAnschriftRepository;
use App\Repository\MitgliedRepository;
use App\Service\Karte\Wohnort;
use App\Support\Datum;

/**
 * The tab "Daten" of an opened member (docs/spec/06-oberflaeche.md section
 * 2): all fields of 07 section 1 in one form. It posts to the routes of
 * app/routes/mitglied.php (App\App\MitgliedController); the first tab, so
 * a member opens on it.
 *
 * At the address it shows the block "Wohnort auf der Karte" (issue #106,
 * app/views/mitglied/wohnort.php): found, not found or not asked yet,
 * with a small map when found.
 */
final readonly class DatenReiter implements Reiter
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
        return 'daten';
    }

    public function titel(): string
    {
        return 'Daten';
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
        return 'mitglied/daten';
    }

    public function daten(int $mitgliedId): array
    {
        $mitglied = new MitgliedRepository($this->dienste->pdo())->finde($mitgliedId);
        if ($mitglied === null) {
            return ['formular' => null];
        }

        $heute = new \DateTimeImmutable('today', new \DateTimeZone(Datum::ZEITZONE));

        return [
            ...MitgliedController::aus($this->dienste)->formularDaten(MitgliedFormular::aus($mitglied), $mitglied),
            'wohnort' => Wohnort::aus($mitglied, new GeoAnschriftRepository($this->dienste->pdo()), $heute),
        ];
    }
}
