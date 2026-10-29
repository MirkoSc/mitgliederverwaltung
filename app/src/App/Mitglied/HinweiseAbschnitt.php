<?php

declare(strict_types=1);

namespace App\App\Mitglied;

use App\Dienste;
use App\Domain\FamilienRolle;
use App\Domain\Mitglied;
use App\Domain\Permission;
use App\Repository\MitgliedRepository;
use App\Service\Mitglied\Hinweis;
use App\Service\Mitglied\HinweisArt;
use App\Service\Mitglied\HinweisLader;

/**
 * The hints at the top of an opened member (docs/spec/07-mitglieder.md
 * section 6, 06 section 2). Computed on every view, so they are always the
 * current state - also right after a save.
 *
 * With the hint "Andere Anschrift als die Familie" the template also shows
 * the payer's address to compare, and a button that copies it into the form
 * of the tab "Daten" (saved only with that form).
 */
final readonly class HinweiseAbschnitt implements Abschnitt
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
        return 'hinweise';
    }

    public function titel(): string
    {
        return 'Hinweise';
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
        return 'mitglied/hinweise';
    }

    public function daten(int $mitgliedId): array
    {
        $pdo = $this->dienste->pdo();
        $repository = new MitgliedRepository($pdo);
        $mitglied = $repository->finde($mitgliedId);
        $hinweise = $mitglied === null ? [] : HinweisLader::aus($pdo)->fuer($mitglied);
        $konto = $this->dienste->view->konto();

        return [
            'hinweise' => $hinweise,
            'zahler' => $mitglied === null ? null : self::zahler($mitglied, $hinweise, $repository),
            'darfBearbeiten' => $konto !== null && $konto->darf(Permission::MitgliederBearbeiten),
        ];
    }

    /**
     * The payer of the family - only when the address hint is shown.
     *
     * @param list<Hinweis> $hinweise
     */
    private static function zahler(Mitglied $mitglied, array $hinweise, MitgliedRepository $repository): ?Mitglied
    {
        $mitAnschrift = array_filter($hinweise, static fn(Hinweis $h): bool => $h->art === HinweisArt::AndereAnschrift);
        if ($mitAnschrift === [] || $mitglied->familieId === null) {
            return null;
        }
        foreach ($repository->derFamilie($mitglied->familieId) as $person) {
            if ($person->familieRolle === FamilienRolle::Zahler && $person->id !== $mitglied->id) {
                return $person;
            }
        }

        return null;
    }
}
