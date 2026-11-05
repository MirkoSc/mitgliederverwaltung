<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Statistik ansehen',
    'kurz' => 'So sehen Sie, wie sich die Mitglieder verteilen. Es werden nur Zahlen gezeigt, keine Namen.',
    'recht' => Permission::ListenSehen,
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Statistik**.',
            'bild' => 'Die Seite Mitglieder. Markiert ist der Eintrag Statistik in der Leiste oben.',
        ],
        [
            'text' => 'Wählen Sie den Reiter **Mitglieder**. Oben stehen die wichtigsten Zahlen, zum Beispiel **Mitglieder**, **aktiv**, **passiv** und **Durchschnittsalter in Jahren**.',
            'bild' => 'Die Seite Statistik. Markiert sind die Zahlen oben auf der Seite.',
            'tipp' => 'Wer die Beiträge verwaltet, sieht zusätzlich den Reiter **Beiträge**. Dort stehen die Summen der Jahresbeiträge.',
        ],
        [
            'text' => 'Darunter folgen die Abschnitte, zum Beispiel **Altersgruppen**, **Abteilungen**, **Wohnorte** und **Eintritte und Austritte**. Wählen Sie in der Liste über den Abschnitten einen Namen, um dorthin zu springen.',
        ],
        [
            'text' => 'Jeder Abschnitt zeigt ein Diagramm. Mit der Maus oder dem Finger sehen Sie die genaue Zahl zu einem Balken.',
        ],
        [
            'text' => 'Wollen Sie alle Zahlen lesen? Wählen Sie unter dem Diagramm **Zahlen als Tabelle**. Die Tabelle klappt auf.',
            'bild' => 'Der Abschnitt Altersgruppen mit dem Diagramm. Markiert ist der Eintrag Zahlen als Tabelle unter dem Diagramm.',
            'tipp' => 'Im Barrierefrei-Modus gibt es keine Diagramme. Dort sehen Sie nur die Tabellen, schon aufgeklappt.',
        ],
        [
            'text' => 'Gezählt werden die heutigen Mitglieder. Wer schon ausgetreten ist, zählt nicht mit. Nur bei **Eintritte und Austritte** sind auch Ehemalige dabei.',
        ],
    ],
];
