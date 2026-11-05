<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Austritt eintragen',
    'kurz' => 'So tragen Sie ein, dass ein Mitglied ausgetreten ist.',
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
            'text' => 'Tragen Sie im Feld **Austrittsdatum** den Tag des Austritts ein.',
            'bild' => 'Das Mitglied auf dem Reiter Daten. Das Feld Austrittsdatum ist markiert.',
        ],
        [
            'text' => 'Tragen Sie im Feld **Austrittsgrund** den Grund ein. Sie können auch einen Vorschlag wählen.',
            'tipp' => 'Der Grund ist freiwillig.',
        ],
        [
            'text' => 'Wählen Sie **Mitglied speichern**.',
        ],
        [
            'text' => 'Oben erscheint die Meldung **Mitglied gespeichert.** Der Austritt ist damit eingetragen.',
            'tipp' => 'Ab dem Austrittsdatum steht neben dem Namen **ehemalig**. Liegt das Datum in der Zukunft, steht dort bis dahin weiter aktiv oder passiv. Das Mitglied bleibt gespeichert. Löschen müssen Sie es nicht. In der Liste sehen Sie es nur, wenn Sie bei **Ehemalige anzeigen** einen Haken setzen.',
            'bild' => 'Das Mitglied nach dem Speichern. Die Meldung Mitglied gespeichert ist markiert.',
        ],
    ],
];
