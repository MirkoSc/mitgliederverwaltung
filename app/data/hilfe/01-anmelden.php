<?php

declare(strict_types=1);

return [
    'titel' => 'Anmelden und abmelden',
    'kurz' => 'So melden Sie sich mit Ihrem Code an und am Ende wieder ab.',
    'recht' => null,
    'schritte' => [
        [
            'text' => 'Öffnen Sie die Mitgliederverwaltung im Browser. Es erscheint die Seite **Anmelden**. Tragen Sie Ihre E-Mail-Adresse in das Feld **E-Mail-Adresse** ein.',
            'bild' => 'Die Seite Anmelden. Markiert ist das Feld E-Mail-Adresse.',
        ],
        [
            'text' => 'Tragen Sie Ihr Passwort in das Feld **Passwort** ein.',
            'tipp' => 'Haben Sie das Passwort vergessen? Wählen Sie **Passwort vergessen?** unter dem Feld.',
        ],
        [
            'text' => 'Wählen Sie **Anmelden**. Es erscheint die Seite **Anmeldung bestätigen**.',
        ],
        [
            'text' => 'Sie bekommen eine E-Mail mit einem 6-stelligen Code. Er gilt 10 Minuten. Tragen Sie den Code in das Feld **Code** ein.',
            'bild' => 'Die Seite Anmeldung bestätigen. Markiert ist das Feld Code.',
            'tipp' => 'Haben Sie eine Authenticator-App eingerichtet? Dann tragen Sie den Code aus der App ein. Haben Sie keinen Zugriff darauf? Tragen Sie einen Ihrer Backup-Codes ein.',
        ],
        [
            'text' => 'Ist die E-Mail nicht da? Wählen Sie **Code erneut senden**. Diesen Knopf gibt es nur beim Code per E-Mail.',
        ],
        [
            'text' => 'Nur auf Ihrem eigenen Gerät: Setzen Sie einen Haken bei **Dieses Gerät 30 Tage merken**. Dann fragt die Anmeldung auf diesem Gerät 30 Tage lang nicht nach dem Code.',
            'tipp' => 'Setzen Sie diesen Haken nicht auf einem Computer, den auch andere benutzen.',
        ],
        [
            'text' => 'Wählen Sie **Anmelden**. Sie sind angemeldet, wenn die Seite **Mitglieder** erscheint.',
        ],
        [
            'text' => 'Sind Sie länger nicht aktiv, erscheint die Meldung **Sind Sie noch da?**. Wählen Sie dann **Weiterarbeiten**. Sonst werden Sie aus Sicherheitsgründen abgemeldet.',
        ],
        [
            'text' => 'Zum Abmelden wählen Sie oben rechts Ihren Namen. Ein Menü öffnet sich.',
            'bild' => 'Das geöffnete Benutzermenü. Markiert ist der Eintrag Abmelden.',
        ],
        [
            'text' => 'Wählen Sie **Abmelden**. Sie sind abgemeldet, wenn wieder die Seite **Anmelden** erscheint.',
        ],
    ],
];
