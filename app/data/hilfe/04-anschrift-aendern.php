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
            'text' => 'Tragen Sie im Feld **Straße und Hausnummer** die neue Straße ein. Beim Tippen erscheinen darunter Vorschläge.',
            'bild' => 'Unter dem Feld „Straße und Hausnummer“ steht eine Liste mit Anschriften. Der erste Vorschlag ist markiert.',
        ],
        [
            'text' => 'Wählen Sie den passenden Vorschlag. Straße, PLZ, Ort und Land sind dann eingetragen.',
            'tipp' => 'Mit der Tastatur: Pfeiltaste nach unten, dann Enter. Passt kein Vorschlag, ändern Sie **PLZ** und **Ort** selbst. Im Feld **Land** filtert Tippen die Liste. Leer heißt Deutschland.',
        ],
        [
            'text' => 'Ändern Sie Telefon oder E-Mail in den Feldern **Telefon**, **Handy** und **E-Mail**.',
        ],
        [
            'text' => 'Wählen Sie unten **Mitglied speichern**.',
            'bild' => 'Der Knopf „Mitglied speichern“ unten im Mitglied ist markiert.',
        ],
        [
            'text' => 'Die Meldung **Mitglied gespeichert.** erscheint.',
            'tipp' => 'Jede Änderung landet im Reiter **Verlauf**. Dort sehen Sie, was sich wann geändert hat.',
        ],
    ],
];
