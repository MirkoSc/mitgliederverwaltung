<?php

declare(strict_types=1);

namespace App\Service\Hilfe;

use App\Domain\Permission;
use App\Domain\Rolle;

/**
 * One guide of the help, read from app/data/hilfe/NN-slug.php
 * (docs/spec/12-hilfe.md section 2) by App\Service\Hilfe\Anleitungen.
 */
final readonly class Anleitung
{
    /**
     * @param list<Schritt> $schritte
     */
    public function __construct(
        /** The address: /app/hilfe/<slug>, taken from the file name. */
        public string $slug,
        public string $titel,
        public string $kurz,
        /** The right its steps need; null = every logged-in account. */
        public ?Permission $recht,
        public array $schritte,
    ) {
    }

    /**
     * Whether the role may carry out the steps - the overview shows only
     * those, and the page answers 404 for the others (12 section 1).
     */
    public function erlaubtFuer(Rolle $rolle): bool
    {
        return $this->recht === null || $rolle->darf($this->recht);
    }

    /**
     * Numbers of the steps that have a picture.
     *
     * @return list<int>
     */
    public function schritteMitBild(): array
    {
        $nummern = [];
        foreach ($this->schritte as $schritt) {
            if ($schritt->bild !== null) {
                $nummern[] = $schritt->nummer;
            }
        }

        return $nummern;
    }
}
