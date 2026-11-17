<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Einzug bestätigen und Rücklastschrift vermerken',
    'kurz' => 'So vermerken Sie, dass die Bank die Beiträge eingezogen hat. Und so tragen Sie eine Lastschrift ein, die zurückgekommen ist.',
    'recht' => Permission::BeitraegeVerwalten,
    'schritte' => [
        [
            'text' => 'Warten Sie, bis die Bank die Beiträge eingezogen hat. Das sehen Sie auf dem Kontoauszug des Vereins. Wählen Sie dann oben in der Leiste **Beiträge**.',
        ],
        [
            'text' => 'Im Abschnitt **Einzug per Lastschrift** steht unter **Erzeugt, wartet auf die Bank** Ihr Einzug. Daneben stehen Fälligkeit, Anzahl und Summe.',
            'bild' => 'Der Abschnitt Erzeugt, wartet auf die Bank. Die Überschrift des Einzugs mit Nummer und Fälligkeit ist markiert, darunter stehen die Haken und Knöpfe.',
        ],
        [
            'text' => 'Setzen Sie beim Einzug einen Haken bei **Ja, die Bank hat diesen Einzug ausgeführt.**',
            'tipp' => 'Ging die Datei nicht an die Bank? Setzen Sie dann den Haken bei **Ja, die Datei ging nicht an die Bank.** und wählen Sie **Einzug verwerfen**. Die Beiträge sind wieder offen. Erzeugen Sie danach einen neuen Einzug.',
        ],
        [
            'text' => 'Wählen Sie **Einzug bestätigen**. Die Meldung **Einzug Nr. … bestätigt: Die Beiträge sind zum … bezahlt.** erscheint.',
            'bild' => 'Die Seite Beiträge nach dem Bestätigen. Die Meldung Einzug bestätigt ist markiert.',
            'tipp' => 'Der Einzug steht danach unten im **Archiv der Einzüge**. Dort können Sie die Lastschriftdatei jederzeit wieder herunterladen.',
        ],
        [
            'text' => 'Kommt später eine Lastschrift zurück? Wählen Sie unten im **Archiv der Einzüge** den Einzug, zum Beispiel **Nr. 1**. Die Seite **Einzug Nr. …** erscheint.',
        ],
        [
            'text' => 'Setzen Sie in der Liste **Beiträge in diesem Einzug** einen Haken bei dem Beitrag, der zurückgekommen ist.',
            'bild' => 'Die Seite des bestätigten Einzugs. In der Liste ist der Haken bei einem Beitrag gesetzt und markiert.',
            'tipp' => 'Die Bank nennt in ihrer Meldung eine Referenz, zum Beispiel **E1-1016-2026**. Diese steht in der Spalte **Referenz**. Sie können sie auch in das Feld **Mitglied suchen** eintragen.',
        ],
        [
            'text' => 'Tragen Sie über der Liste im Feld **Vermerk** den Grund ein, zum Beispiel **Konto erloschen**.',
        ],
        [
            'text' => 'Wählen Sie **Ausgewählte als Rücklastschrift zurücksetzen**. Die Meldung **1 Beitrag als Rücklastschrift vermerkt (…) – wieder offen.** erscheint.',
            'bild' => 'Die Seite des Einzugs nach dem Zurücksetzen. Die Meldung Beitrag als Rücklastschrift vermerkt ist markiert.',
            'tipp' => 'Der Beitrag ist jetzt wieder offen. Beim nächsten Einzug hat er keinen Haken. Setzen Sie ihn selbst, wenn er erneut eingezogen werden soll. Am Mitglied steht das Jahr der Rücklastschrift. Klären Sie mit dem Mitglied, wie es zahlt.',
        ],
    ],
];
