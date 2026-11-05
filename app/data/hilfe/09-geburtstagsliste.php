<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Geburtstagsliste anzeigen und exportieren',
    'kurz' => 'So sehen Sie, wer Geburtstag hat, und speichern die Liste als Datei.',
    'recht' => Permission::ListenSehen,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Listen**.',
            'bild' => 'Die Seite Listen. Markiert ist der Eintrag Listen in der Leiste oben.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Geburtstage**. Die Liste zeigt, wer im gewählten Zeitraum Geburtstag hat.',
        ],
        [
            'text' => 'Wählen Sie im Feld **Zeitraum** den Eintrag **Woche**, **Monat** oder **Jahr**. Wählen Sie dann **Anzeigen**.',
            'bild' => 'Die Geburtstagsliste. Markiert ist das Feld Zeitraum.',
        ],
        [
            'text' => 'Wählen Sie **Vor** oder **Zurück**, um zum nächsten oder vorigen Zeitraum zu wechseln.',
        ],
        [
            'text' => 'Wollen Sie nur runde Geburtstage sehen? Setzen Sie einen Haken bei **Nur runde Geburtstage**. Wählen Sie dann **Anzeigen**.',
        ],
        [
            'text' => 'Wählen Sie im Feld **Dateiformat** den Eintrag **Excel-Datei (xlsx)** oder **CSV-Datei (csv)**.',
            'bild' => 'Der Bereich Liste exportieren unter der Liste. Markiert ist das Feld Dateiformat.',
        ],
        [
            'text' => 'Wählen Sie **Liste exportieren**. Die Datei wird auf Ihr Gerät geladen. Sie enthält alle Einträge der Auswahl.',
            'tipp' => 'Die Datei enthält Mitgliederdaten. Löschen Sie sie, sobald Sie sie nicht mehr brauchen.',
        ],
        [
            'text' => 'Zum Drucken öffnen Sie die Datei im Tabellenprogramm. Drucken Sie sie dort.',
        ],
    ],
];
