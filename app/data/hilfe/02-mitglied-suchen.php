<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Mitglied suchen und öffnen',
    'kurz' => 'So finden Sie ein Mitglied und sehen seine Daten.',
    'recht' => Permission::MitgliederSehen,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Mitglieder**.',
            'bild' => 'Die Leiste oben. Der Eintrag „Mitglieder“ ist markiert.',
        ],
        [
            'text' => 'Tragen Sie im Feld **Mitglied suchen** einen Namen oder eine Nummer ein.',
            'bild' => 'Die Seite Mitglieder. Das Feld „Mitglied suchen“ ist markiert und enthält einen Namen. Die Liste zeigt nur noch passende Mitglieder.',
            'tipp' => 'Die Liste ändert sich beim Tippen. Sie dürfen mehrere Wörter eintragen, zum Beispiel Vorname und Ort.',
        ],
        [
            'text' => 'Wollen Sie nur eine Gruppe sehen? Wählen Sie bei **Abteilung**, **Status** oder **Beitragsart** einen Eintrag.',
            'tipp' => 'Mit **Filter zurücksetzen** zeigt die Liste wieder alle Mitglieder.',
        ],
        [
            'text' => 'Setzen Sie einen Haken bei **Ehemalige anzeigen**, wenn Sie ein ausgetretenes Mitglied suchen.',
            'tipp' => 'Ohne diesen Haken fehlen ausgetretene Mitglieder in der Liste.',
        ],
        [
            'text' => 'Reicht das nicht? Wählen Sie **Erweiterte Suche**. Es klappen Felder auf, zum Beispiel **Ort**, **Zahlart** oder **Eintritt**. Füllen Sie die Felder aus, die Sie kennen.',
            'bild' => 'Die Seite Mitglieder mit aufgeklappter Erweiterter Suche. Die Überschrift „Erweiterte Suche“ ist markiert, darunter stehen die Felder.',
            'tipp' => 'Alle ausgefüllten Felder müssen zugleich passen, auch zusammen mit dem Suchtext. Wer im Feld **Austritt** etwas einträgt, sieht automatisch auch Ehemalige.',
        ],
        [
            'text' => 'Wählen Sie in der Liste den Namen des Mitglieds.',
            'bild' => 'Das geöffnete Mitglied mit seinen Reitern. Der Knopf zum Zurückkehren zur Liste ist markiert.',
        ],
        [
            'text' => 'Wählen Sie einen Reiter wie **Daten** oder **Verlauf**, um mehr zu sehen.',
        ],
        [
            'text' => 'Wählen Sie **Zurück zur Liste** (ohne Barrierefrei-Modus: **Schließen**). Danach sehen Sie wieder die Liste.',
        ],
    ],
];
