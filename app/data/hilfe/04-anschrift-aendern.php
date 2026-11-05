<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Anschrift, Telefon oder E-Mail ändern',
    'kurz' => 'So ändern Sie die Kontaktdaten eines Mitglieds.',
    'recht' => Permission::MitgliederBearbeiten,
    'schritte' => [
        [
            'text' => 'Öffnen Sie das Mitglied. Wie das geht, steht in der Anleitung „Mitglied suchen und öffnen“.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Daten**.',
            'bild' => 'Das geöffnete Mitglied. Der Reiter „Daten“ ist markiert.',
        ],
        [
            'text' => 'Ändern Sie die Anschrift in den Feldern **Straße und Hausnummer**, **PLZ** und **Ort**.',
            'bild' => 'Das Formular des Mitglieds. Das Feld „Straße und Hausnummer“ ist markiert.',
        ],
        [
            'text' => 'Ändern Sie Telefon oder E-Mail in den Feldern **Telefon**, **Handy** und **E-Mail**.',
        ],
        [
            'text' => 'Wählen Sie **Mitglied speichern**.',
            'bild' => 'Der Knopf „Mitglied speichern“ am Ende des Formulars ist markiert.',
        ],
        [
            'text' => 'Die Meldung **Mitglied gespeichert.** erscheint.',
            'tipp' => 'Jede Änderung landet im Reiter **Verlauf**. Dort sehen Sie, was sich wann geändert hat.',
        ],
    ],
];
