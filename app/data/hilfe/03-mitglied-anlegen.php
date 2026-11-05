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
            'text' => 'Tragen Sie im Feld **Nachname** den Namen ein.',
            'bild' => 'Die Seite Neues Mitglied. Das Feld „Nachname“ ist markiert.',
            'tipp' => 'Pflicht ist nur der Nachname. Die Mitgliedsnummer vergibt das Programm beim Speichern. Den Rest können Sie später ergänzen.',
        ],
        [
            'text' => 'Tragen Sie bei Bedarf **Vorname** und **Geburtsdatum** ein.',
        ],
        [
            'text' => 'Tragen Sie im Feld **Straße und Hausnummer** die Straße ein. Beim Tippen erscheinen darunter Vorschläge.',
            'bild' => 'Unter dem Feld „Straße und Hausnummer“ steht eine Liste mit Anschriften. Der erste Vorschlag ist markiert.',
        ],
        [
            'text' => 'Wählen Sie den passenden Vorschlag. Straße, PLZ, Ort und Land sind dann eingetragen.',
            'tipp' => 'Mit der Tastatur: Pfeiltaste nach unten, dann Enter. Passt kein Vorschlag, tragen Sie **PLZ** und **Ort** selbst ein. Im Feld **Land** filtert Tippen die Liste. Leer heißt Deutschland.',
        ],
        [
            'text' => 'Tragen Sie bei Bedarf im Feld **IBAN** die IBAN ein. Bei einer deutschen IBAN erscheinen BIC und Bank von selbst.',
            'tipp' => 'Ein roter Rahmen um das Feld heißt: Die IBAN stimmt nicht. Prüfen Sie die Ziffern. Speichern geht trotzdem.',
        ],
        [
            'text' => 'Wählen Sie unten **Mitglied anlegen**.',
        ],
        [
            'text' => 'Die Meldung **Mitglied angelegt.** erscheint. Das neue Mitglied ist nun geöffnet. Neben dem Namen steht seine Nummer.',
            'bild' => 'Das neue Mitglied ist geöffnet. Oben steht die Meldung „Mitglied angelegt.“.',
            'tipp' => 'Erscheint oben ein Hinweis zum Mitglied, ist es trotzdem gespeichert. Der Hinweis sagt nur, was noch fehlt oder nicht passt.',
        ],
    ],
];
