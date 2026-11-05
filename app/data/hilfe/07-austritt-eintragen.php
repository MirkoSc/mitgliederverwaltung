<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Austritt eintragen',
    'kurz' => 'So tragen Sie ein, dass ein Mitglied ausgetreten ist.',
    'recht' => Permission::MitgliederBearbeiten,
    'schritte' => [
        [
            'text' => 'Öffnen Sie das Mitglied. Wie das geht, steht in der Anleitung „Mitglied suchen und öffnen“.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Daten**.',
        ],
        [
            'text' => 'Wählen Sie unter dem Formular den Knopf **Mitglied ausgetreten**.',
            'bild' => 'Das Mitglied auf dem Reiter Daten. Der Knopf Mitglied ausgetreten unter dem Formular ist markiert.',
        ],
        [
            'text' => 'Die Seite **Mitglied ausgetreten** öffnet sich. Im Feld **Austrittsdatum** steht schon der heutige Tag. Tragen Sie bei Bedarf einen anderen Tag ein.',
            'tipp' => 'Sie können auch einen Tag in der Zukunft eintragen. Dann ist der Austritt nur vermerkt. Bis zu diesem Tag bleibt die Person Mitglied.',
            'bild' => 'Die Seite Mitglied ausgetreten. Das Feld Austrittsdatum ist markiert.',
        ],
        [
            'text' => 'Tragen Sie im Feld **Austrittsgrund** den Grund ein. Sie können auch einen Vorschlag wählen.',
            'tipp' => 'Der Grund ist freiwillig. Vorschläge sind **Austritt** und **verstorben**.',
        ],
        [
            'text' => 'Wählen Sie **Austritt eintragen**.',
        ],
        [
            'text' => 'Oben erscheint die Meldung **Austritt zum … eingetragen – das Mitglied ist jetzt ehemalig.** Neben dem Namen steht jetzt **ehemalig**.',
            'tipp' => 'Bei einem Tag in der Zukunft lautet die Meldung **Austritt zum … vermerkt – bis dahin bleibt es Mitglied.** Ein ehemaliges Mitglied wird nicht mehr eingezogen. Es steht in keiner Liste mehr. Die Statistik zählt es nur noch bei den Austritten.',
            'bild' => 'Das Mitglied nach dem Eintragen. Die Meldung Austritt eingetragen ist markiert.',
        ],
        [
            'text' => 'War der Austritt ein Versehen? Wählen Sie im Mitglied unter dem Formular **Austritt zurücknehmen**.',
            'tipp' => 'Ein ehemaliges Mitglied finden Sie in der Liste nur mit einem Haken bei **Ehemalige anzeigen**.',
        ],
        [
            'text' => 'Wählen Sie auf der Rückfrage noch einmal **Austritt zurücknehmen**. Es erscheint die Meldung **Austritt zurückgenommen.**',
        ],
    ],
];
