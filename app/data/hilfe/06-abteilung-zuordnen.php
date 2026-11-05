<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Mitglied einer Abteilung zuordnen',
    'kurz' => 'So tragen Sie ein, in welchen Abteilungen ein Mitglied ist.',
    'recht' => Permission::MitgliederBearbeiten,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Mitglieder** und öffnen Sie das Mitglied in der Liste.',
            'tipp' => 'Wie Sie ein Mitglied finden, steht in der Anleitung „Mitglied suchen und öffnen“.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Daten**. Suchen Sie die Gruppe **Mitgliedschaft**.',
        ],
        [
            'text' => 'Setzen Sie bei **Abteilungen** einen Haken bei jeder Abteilung des Mitglieds.',
            'tipp' => 'Es sind mehrere Abteilungen möglich. Einen Haken entfernen Sie mit der Leertaste oder einem Klick.',
            'bild' => 'Das Mitglied auf dem Reiter Daten. Die Gruppe Abteilungen mit den Haken ist markiert.',
        ],
        [
            'text' => 'Wählen Sie **Mitglied speichern**.',
        ],
        [
            'text' => 'Oben erscheint die Meldung **Mitglied gespeichert.** Die Abteilungen stehen nun beim Mitglied.',
            'bild' => 'Das Mitglied nach dem Speichern. Die Meldung Mitglied gespeichert ist markiert.',
        ],
    ],
];
