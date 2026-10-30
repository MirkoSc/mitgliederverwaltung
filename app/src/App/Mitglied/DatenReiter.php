<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\App\MitgliedController;
use App\Dienste;
use App\Domain\Permission;
use App\Repository\GeoAnschriftRepository;
use App\Repository\KartenRepository;
use App\Repository\MitgliedRepository;
use App\Service\Karte\Kartenmitte;
use App\Service\Karte\Wohnort;
use App\Support\Datum;

/**
 * The tab "Daten" of an opened member (docs/spec/06-oberflaeche.md section
 * 2): all fields of 07 section 1 in one form. It posts to the routes of
 * app/routes/mitglied.php (App\App\MitgliedController); the first tab, so
 * a member opens on it.
 *
 * At the address it shows the block "Wohnort auf der Karte" (issue #106,
 * app/views/mitglied/wohnort.php): found, approximately found, set by
 * hand, not found or not asked yet, with a small map when there is a dot.
 * Whoever may edit gets the small map without a dot too, to set one by
 * hand (issue #108): it opens at Kartenmitte, from the dots there are.
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
        $pdo = $this->dienste->pdo();
        $geo = new GeoAnschriftRepository($pdo);
        $wohnort = Wohnort::aus($mitglied, $geo, $heute);
        $mitte = null;
        if ($wohnort->suchbar() && !$wohnort->zustand->hatPunkt() && $this->dienste->view->konto()?->darf(Permission::MitgliederBearbeiten) === true) {
            $mitte = Kartenmitte::fuer($wohnort->anschrift, new KartenRepository($pdo)->anschriften($heute), $geo->alle());
        }

        return [
            ...MitgliedController::aus($this->dienste)->formularDaten(MitgliedFormular::aus($mitglied), $mitglied),
            'wohnort' => $wohnort,
            'wohnortMitte' => $mitte,
        ];
    }
}
