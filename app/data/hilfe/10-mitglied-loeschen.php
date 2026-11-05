<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Ehemaliges Mitglied löschen',
    'kurz' => 'So löschen Sie ein ausgetretenes Mitglied endgültig.',
    'recht' => Permission::MitgliederLoeschen,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Mitglieder**.',
        ],
        [
            'text' => 'Setzen Sie einen Haken bei **Ehemalige anzeigen**. Die Liste zeigt dann auch ausgetretene Mitglieder.',
            'tipp' => 'Löschen können Sie nur ehemalige Mitglieder. Ein aktives Mitglied bekommt zuerst einen Austritt. Das steht in der Anleitung „Austritt eintragen“.',
        ],
        [
            'text' => 'Öffnen Sie das Mitglied in der Liste. Neben dem Namen steht **ehemalig**.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Daten**.',
        ],
        [
            'text' => 'Wählen Sie unter dem Formular den Knopf **Mitglied löschen**.',
            'bild' => 'Das ehemalige Mitglied auf dem Reiter Daten. Der Knopf Mitglied löschen unter dem Formular ist markiert.',
        ],
        [
            'text' => 'Die Seite **Mitglied löschen?** sagt, was alles gelöscht wird. Lesen Sie den Text. Wählen Sie dann **Weiter**.',
            'tipp' => 'Ein ehemaliges Mitglied müssen Sie nicht löschen. Wollen Sie es behalten, wählen Sie **Nicht löschen, zurück zum Mitglied**.',
            'bild' => 'Die Seite Mitglied löschen? mit dem Text zum Löschen. Der Knopf Weiter ist markiert.',
        ],
        [
            'text' => 'Die Seite **Endgültig löschen?** erscheint. Tragen Sie im Feld **Mitgliedsnummer zur Bestätigung eintippen** die Nummer des Mitglieds ein.',
            'tipp' => 'Die Nummer steht oben neben dem Namen.',
            'bild' => 'Die Seite Endgültig löschen? Das Feld für die Mitgliedsnummer ist markiert.',
        ],
        [
            'text' => 'Wählen Sie **Mitglied endgültig löschen**.',
            'tipp' => 'Das lässt sich nicht rückgängig machen. Verlauf, Nachweise und Jahresbeiträge des Mitglieds sind danach auch weg.',
        ],
        [
            'text' => 'Die Seite **Mitglieder** erscheint mit der Meldung **Mitglied Nr. … ist gelöscht.** Das Mitglied steht nicht mehr in der Liste.',
            'bild' => 'Die Seite Mitglieder nach dem Löschen. Die Meldung Mitglied gelöscht ist markiert.',
        ],
    ],
];
