<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Beiträge per Lastschrift einziehen',
    'kurz' => 'So erzeugen Sie die Lastschriftdatei für die Beiträge und geben sie der Bank.',
    'recht' => Permission::BeitraegeVerwalten,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Beiträge**. Die Seite zeigt das laufende Beitragsjahr.',
            'tipp' => 'Ein anderes Jahr wählen Sie mit den Knöpfen **Jahr …** links und rechts über dem Abschnitt **Jahresbeiträge**.',
        ],
        [
            'text' => 'Wählen Sie **Beiträge für … erzeugen**. Statt der Punkte steht dort das Jahr. Die Meldung **… Jahresbeiträge für … erzeugt.** erscheint.',
            'bild' => 'Die Seite Beiträge mit der Meldung oben. Der Knopf Beiträge für 2026 erzeugen ist markiert.',
            'tipp' => 'Sie können den Knopf jederzeit erneut wählen. Es kommen nur fehlende Beiträge dazu, zum Beispiel für Neueintritte. Darüber steht, wie viele Beiträge **offen**, **im Einzug** und **bezahlt** sind.',
        ],
        [
            'text' => 'Im Abschnitt **Einzug per Lastschrift** stehen Anzahl und Summe der offenen Beiträge mit Lastschrift. Darunter steht **… Beiträge ansehen und abwählen**. Ist die Liste zugeklappt, wählen Sie diese Zeile.',
            'bild' => 'Der Abschnitt Einzug per Lastschrift mit Anzahl und Summe. Die aufgeklappte Zeile Beiträge ansehen und abwählen ist markiert, darunter beginnt die Liste mit Haken.',
            'tipp' => 'Steht dort **Noch keine Lastschriftdatei möglich.**? Dann fehlen die IBAN oder die Gläubiger-ID des Vereins. Wählen Sie **Vereinsdaten in den Einstellungen ergänzen**. Tragen Sie unter **Bankverbindung** die **IBAN** und die **Gläubiger-ID** ein und wählen Sie **Vereinsdaten speichern**.',
        ],
        [
            'text' => 'Jeder Beitrag in der Liste hat einen Haken. Entfernen Sie den Haken bei jedem Beitrag, der diesmal nicht eingezogen werden soll.',
            'tipp' => 'Abgewählte Beiträge bleiben offen. Beim nächsten Einzug sind sie wieder dabei. Einen Namen finden Sie schnell mit dem Feld **Mitglied suchen** über der Liste.',
        ],
        [
            'text' => 'Wollen Sie wissen, wer fehlt? Wählen Sie weiter unten **… offene Beiträge lassen sich nicht per Lastschrift einziehen**. Bei jedem Beitrag steht der Grund.',
            'tipp' => 'Selbstzahler und Beiträge ohne gültige IBAN kassieren Sie selbst. Sie stehen auch in der Liste **Selbst kassieren**. Von ausgetretenen Mitgliedern wird nichts eingezogen.',
        ],
        [
            'text' => 'Prüfen Sie das Feld **Fällig am**. An diesem Tag bucht die Bank die Beiträge ab.',
            'bild' => 'Der untere Teil des Abschnitts Einzug per Lastschrift. Das Feld Fällig am ist markiert. Darunter stehen der Verwendungszweck, der Knopf Einzug erzeugen und die Beiträge, die sich nicht einziehen lassen.',
            'tipp' => 'Vorgeschlagen ist der nächste Bankarbeitstag plus ein Tag. So ist die Datei rechtzeitig bei der Bank. Ist der Tag zu früh oder ohne Bankbetrieb, etwa ein Sonntag, erscheint nach dem Erzeugen eine Warnung.',
        ],
        [
            'text' => 'Lesen Sie darunter den **Verwendungszweck**. So steht er später auf dem Kontoauszug der Mitglieder.',
            'tipp' => 'Die Vorlage ändern Sie unter **Einstellungen** bei den Vereinsdaten im Feld **Vorlage für den Verwendungszweck**. Die Platzhalter in geschweiften Klammern stehen unter dem Verwendungszweck erklärt.',
        ],
        [
            'text' => 'Wählen Sie **Einzug erzeugen**. Die Seite **Einzug Nr. …** erscheint mit der Meldung **Einzug Nr. … erzeugt: …**. Die Beiträge stehen jetzt **im Einzug**.',
        ],
        [
            'text' => 'Wählen Sie **Lastschriftdatei herunterladen**. Die Datei wird auf Ihr Gerät geladen. Sie heißt zum Beispiel **lastschrift-2026-1.xml**.',
            'bild' => 'Die Seite des neuen Einzugs. Der Knopf Lastschriftdatei herunterladen ist markiert.',
            'tipp' => 'Die Datei bleibt in der Mitgliederverwaltung gespeichert und ist bei jedem Herunterladen dieselbe. Sie enthält Bankdaten der Mitglieder. Löschen Sie sie von Ihrem Gerät, sobald die Bank sie hat.',
        ],
        [
            'text' => 'Melden Sie sich im Online-Banking des Vereinskontos an. Reichen Sie die Datei dort als SEPA-Lastschrift ein und geben Sie den Auftrag frei. Die Bank bucht die Beiträge am Fälligkeitstag ab.',
            'tipp' => 'Bis dahin steht der Einzug auf der Seite **Beiträge** unter **Erzeugt, wartet auf die Bank**. Wie es weitergeht, steht in der Anleitung „Einzug bestätigen und Rücklastschrift vermerken“.',
        ],
    ],
];
