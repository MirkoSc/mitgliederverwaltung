<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Beitragsart, Zahlart oder IBAN ändern',
    'kurz' => 'So ändern Sie, was ein Mitglied zahlt und wie es zahlt. Ändern Sie nur, was sich geändert hat, und überspringen Sie die übrigen Schritte.',
    'recht' => Permission::MitgliederBearbeiten,
    'schritte' => [
        [
            'text' => 'Öffnen Sie das Mitglied. Wie das geht, steht in der Anleitung „Mitglied suchen und öffnen“.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Daten**. Suchen Sie die Gruppe **Beitrag**.',
            'bild' => 'Das geöffnete Mitglied auf dem Reiter Daten. Die Überschrift der Gruppe Beitrag ist markiert.',
        ],
        [
            'text' => 'Wählen Sie im Feld **Beitragsart** die neue Beitragsart aus der Liste.',
        ],
        [
            'text' => 'Wählen Sie bei **Zahlart** entweder **Lastschrift** oder **Selbstzahler**.',
            'tipp' => 'Bei **Selbstzahler** zahlt das Mitglied selbst. Es wird nichts eingezogen.',
        ],
        [
            'text' => 'Tragen Sie im Feld **IBAN** die neue IBAN ein. Leerzeichen sind erlaubt.',
            'tipp' => 'Die IBAN wird beim Tippen geprüft. Ist sie falsch, erscheint ein roter Rahmen und der Text „Die Prüfziffer passt nicht.“ Schreiben Sie sie dann vom Kontoauszug neu ab. Speichern geht trotzdem. Oben im Mitglied steht danach ein Hinweis.',
        ],
        [
            'text' => 'Sehen Sie das Feld **BIC**? Bei einer deutschen IBAN und leerem Feld füllt es sich von selbst. Darunter steht der Name der Bank.',
            'tipp' => 'Markiert das Feld die BIC rot („Die BIC passt nicht zur IBAN“), prüfen Sie die BIC. Oder leeren Sie das Feld, dann wird sie ergänzt.',
        ],
        [
            'text' => 'Wählen Sie **Mitglied speichern**.',
        ],
        [
            'text' => 'Oben erscheint die Meldung **Mitglied gespeichert.** Die Änderung ist damit übernommen.',
            'tipp' => 'Erscheint danach ein gelber Hinweis, zum Beispiel ein fehlender Nachweis, ist trotzdem gespeichert. Der Hinweis sagt nur, was noch zu tun ist. Ein schon erzeugter Jahresbeitrag ändert sich durch die Änderung nicht. Das erledigt der Admin.',
            'bild' => 'Das Mitglied nach dem Speichern. Die Meldung Mitglied gespeichert ist markiert.',
        ],
    ],
];
