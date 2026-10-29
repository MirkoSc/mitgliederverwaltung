<?php

declare(strict_types=1);

namespace App\Admin\Einstellungen;

use App\Dienste;
use App\Domain\Permission;
use App\Domain\Rechtstext;
use App\Service\Rechtstexte\Rechtstexte;

/**
 * The settings section "Rechtstexte": one text field per legal text, each
 * posting to its own route (app/routes/rechtstexte.php).
 */
final readonly class RechtstexteAbschnitt implements Abschnitt
{
    public function __construct(private Rechtstexte $rechtstexte)
    {
    }

    public static function erzeugen(Dienste $dienste): self
    {
        return new self(Rechtstexte::aus($dienste));
    }

    public function schluessel(): string
    {
        return 'rechtstexte';
    }

    public function titel(): string
    {
        return 'Rechtstexte';
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
        return 'admin/einstellungen/rechtstexte';
    }

    public function daten(): array
    {
        $felder = [];
        foreach (Rechtstext::cases() as $art) {
            $felder[] = [
                'art' => $art->value,
                'titel' => $art->titel(),
                'pfad' => $art->pfad(),
                'text' => $this->rechtstexte->text($art),
            ];
        }

        return [
            'felder' => $felder,
            'maxZeichen' => Rechtstexte::MAX_ZEICHEN,
        ];
    }
}
