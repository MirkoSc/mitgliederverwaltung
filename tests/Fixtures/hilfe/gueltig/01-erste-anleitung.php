<?php

declare(strict_types=1);

use App\Domain\Permission;

// Made-up guide for AnleitungenTest and HilfeTest (issue #114).
return [
    'titel' => 'Erste Anleitung',
    'kurz' => 'Für jeden angemeldeten Benutzer.',
    'recht' => null,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Mitglieder**. <script>alert(1)</script>',
            'bild' => 'Die Seite Mitglieder mit dem markierten Knopf „Neues Mitglied".',
        ],
        [
            'text' => 'Wählen Sie den Knopf **Neues Mitglied**.',
            'tipp' => '**Mitgliedsnummer** und Nachname genügen. <b>nicht fett</b>',
        ],
        [
            'text' => 'Sie sehen die Meldung „Mitglied gespeichert".',
            'bild' => 'Die Meldung „Mitglied gespeichert" oben auf der Seite.',
        ],
    ],
];
