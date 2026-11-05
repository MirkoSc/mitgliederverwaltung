<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Beitragsart, Zahlart oder IBAN ändern',
    'kurz' => 'So ändern Sie, was ein Mitglied zahlt und wie es zahlt.',
    'recht' => Permission::MitgliederBearbeiten,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Mitglieder** und öffnen Sie das Mitglied in der Liste.',
            'tipp' => 'Wie Sie ein Mitglied finden, steht in der Anleitung „Mitglied suchen und öffnen“.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Daten**. Suchen Sie die Gruppe **Beitrag**.',
            'bild' => 'Das geöffnete Mitglied auf dem Reiter Daten. Die Gruppe Beitrag mit dem Feld Beitragsart ist markiert.',
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
