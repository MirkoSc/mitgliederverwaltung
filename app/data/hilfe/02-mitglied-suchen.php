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
            'bild' => 'Die Seite Mitglieder. Das Feld „Mitglied suchen“ ist markiert und enthält eine Nummer.',
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
