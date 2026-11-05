<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Neues Mitglied anlegen',
    'kurz' => 'So tragen Sie ein neues Mitglied ein.',
    'recht' => Permission::MitgliederBearbeiten,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Mitglieder**.',
        ],
        [
            'text' => 'Wählen Sie den Knopf **Neues Mitglied**.',
            'bild' => 'Die Seite Mitglieder. Der Knopf „Neues Mitglied“ ist markiert.',
        ],
        [
            'text' => 'Prüfen Sie im Feld **Mitgliedsnummer** die Nummer. Sie ist schon eingetragen.',
            'bild' => 'Die Seite Neues Mitglied. Das Feld „Mitgliedsnummer“ ist markiert.',
            'tipp' => 'Pflicht sind nur Mitgliedsnummer und Nachname. Den Rest können Sie später ergänzen.',
        ],
        [
            'text' => 'Tragen Sie im Feld **Nachname** den Namen ein.',
        ],
        [
            'text' => 'Tragen Sie bei Bedarf **Vorname**, **Geburtsdatum** und die Anschrift ein.',
        ],
        [
            'text' => 'Wählen Sie **Mitglied anlegen**.',
            'bild' => 'Der Knopf „Mitglied anlegen“ am Ende des Formulars ist markiert.',
        ],
        [
            'text' => 'Die Meldung **Mitglied angelegt.** erscheint. Das neue Mitglied ist nun geöffnet.',
            'bild' => 'Das neue Mitglied ist geöffnet. Oben steht die Meldung „Mitglied angelegt.“.',
            'tipp' => 'Erscheint oben ein Hinweis zum Mitglied, ist es trotzdem gespeichert. Der Hinweis sagt nur, was noch fehlt oder nicht passt.',
        ],
    ],
];
