# 12 – Hilfe

Eine Hilfe in der Anwendung: wenige bebilderte Anleitungen für die
häufigsten Aufgaben, Schritt für Schritt. Ausgerichtet auf Benutzer im
**Barrierefrei-Modus** (06 §4): einfache Sprache, ein Schritt je Handlung,
Bilder passend zum Modus. Mit Mirko abgestimmt am 05.11.2026 (#113).

Bewusst **nicht**: Suche in der Hilfe, „?"-Knöpfe an den Seiten, Videos,
Anleitungen für seltene Admin-Abläufe. Mehr nur auf ausdrücklichen Wunsch.

## 1. Seiten und Zugriff

- Eintrag **„Hilfe"** im Benutzermenü, vor „Abmelden" (06 §2 Punkt 8) – kein
  achter Bereich in der Hauptnavigation.
- `GET /app/hilfe` – Übersicht. `GET /app/hilfe/{slug}` – eine Anleitung
  (`slug` nur `[a-z0-9-]+`). Beide `Zugriff::angemeldet()`.
- Jede Anleitung nennt das Recht, das man für ihre Schritte braucht (§2).
  Die Übersicht zeigt nur Anleitungen, die die Rolle ausführen darf; eine
  nicht erlaubte oder unbekannte Anleitung antwortet mit **404** (Prüfung im
  Controller, weil das Recht je Anleitung wechselt – Kommentar in
  `app/routes/hilfe.php`). Die Anleitungen sind nicht geheim; der Filter
  erspart Benutzern nur Anleitungen, die sie nicht ausführen können.
- Keine Schreib-Routen, keine Mitgliederdaten.

## 2. Anleitungs-Dateien

Eine Anleitung ist **eine Datei** `app/data/hilfe/NN-slug.php`. `NN`
(zweistellig) bestimmt die Reihenfolge, `slug` die Adresse. Es gibt keine
zentrale Liste: die Hilfe liest das Verzeichnis (Erweiterungspunkt wie in
05 §6). Wer eine Anleitung schreibt, ändert nur seine Datei.

```php
<?php

declare(strict_types=1);

use App\Domain\Permission;

return [
    'titel' => 'Neues Mitglied anlegen',
    'kurz' => 'So tragen Sie ein neues Mitglied ein.',
    'recht' => Permission::MitgliederBearbeiten, // null = jeder angemeldete Benutzer
    'schritte' => [
        [
            'text' => 'Wählen Sie oben in der Leiste **Mitglieder**.',
            'bild' => 'Die Seite Mitglieder. Rechts neben der Überschrift ist der Knopf „Neues Mitglied" markiert.',
        ],
        [
            'text' => 'Wählen Sie den Knopf **Neues Mitglied**.',
            'tipp' => 'Pflicht sind nur Mitgliedsnummer und Nachname. Den Rest können Sie später ergänzen.',
        ],
    ],
];
```

- Schlüssel genau `titel`, `kurz`, `recht`, `schritte`; je Schritt `text`
  (Pflicht), `bild` (optional, = Alternativtext des Bildes) und `tipp`
  (optional). Unbekannte Schlüssel, leere Texte, ein `recht`, das weder
  `null` noch ein `Permission` ist, oder mehr als 12 Schritte sind ein
  Fehler (Test, §7).
- `text` und `tipp` sind reiner Text; einzige Auszeichnung ist `**fett**`
  für Bezeichnungen der Oberfläche. Ausgegeben wird escaped (über
  `App\Support\Markdown::fett()`, ohne die Ausgabe der Rechtstexte zu
  ändern).
- Ein Schritt mit `bild` hat zwei Bilder (§6):
  `public/img/hilfe/<slug>/<NN>-normal.png` und
  `public/img/hilfe/<slug>/<NN>-barrierefrei.png`, `NN` = Nummer des
  Schritts, zweistellig, ab `01`.
- `tools/hilfe-anleitungen.php [<verzeichnis>]` gibt alle Anleitungen als
  JSON aus – so liest das Screenshot-Skript dieselben Daten wie die
  Anwendung. Eine Liste in Reihenfolge der Dateien, je Anleitung
  `{"slug": "mitglied-anlegen", "titel": "…", "recht": "mitglieder.bearbeiten"
  oder null, "bilder": [1, 4]}` (`bilder` = Nummern der Schritte mit
  `bild`). Eine ungültige Datei: Grund auf STDERR, Exit-Code 1.
- Geladen wird über `App\Service\Hilfe\Anleitungen` (#114), streng: eine
  ungültige Datei wirft `UngueltigeAnleitung` statt übersprungen zu werden;
  zwei Dateien mit demselben `slug` sind ebenfalls ein Fehler.

## 3. Darstellung

Bausteine aus dem Designsystem (06 §3), neue Klassen in
`public/css/teile/hilfe.css` (nur Tokens, eigene Regeln für
`.modus-barrierefrei`) und als Baustein „Anleitung" auf der Musterseite.
Kein JavaScript.

- **Übersicht:** Überschrift „Hilfe", ein Satz, darunter die Anleitungen als
  Auswahlliste (`auswahl`: Titel als Link, darunter `kurz`). Darunter fest im
  Template ein Kasten „Mit der Tastatur bedienen" (Tab, Umschalt + Tab,
  Enter, Leertaste für Haken, „Zum Inhalt springen").
- **Anleitung:** Link „Alle Anleitungen", Überschrift = `titel`, darunter
  `kurz`, dann die Schritte als `<ol>` mit großen, gut sichtbaren Nummern.
  Je Schritt: Text, ggf. `tipp` als `hinweis-info`, ggf. Bild. Unten „Nächste
  Anleitung: …" (nur erlaubte) und „Alle Anleitungen".
- **Bild:** die Variante zum Modus des Kontos (`-barrierefrei.png` bzw.
  `-normal.png`), `alt` = `bild`, `width`/`height` aus der Datei, Adresse mit
  `?v=<Version>` (Cache, 01). Fehlt die Datei, entfällt das Bild – der Text
  trägt allein. Bilder nutzen höchstens die Breite des Inhalts, mit Rahmen.
- Im Barrierefrei-Modus: größere Schrittnummern und Abstände, Bilder über
  die ganze Breite.

## 4. Schreibregeln (verbindlich für jede Anleitung)

1. Anrede „Sie", kurze Sätze (möglichst unter 15 Wörtern), Aktiv. Ein
   Schritt = eine Handlung. Höchstens 10 Schritte; mehr → Anleitung teilen.
2. Bezeichnungen der Oberfläche **wörtlich** aus `app/views/` und **fett**:
   „Wählen Sie **Mitglied speichern**." Nie eine Bezeichnung erfinden.
3. „Wählen Sie …" statt „Klicken Sie …" (gilt für Maus, Finger und
   Tastatur); bei Feldern „Tragen Sie … ein", bei Haken „Setzen Sie einen
   Haken bei …".
4. Alltagswörter: Seite, Knopf, Feld, Haken, Reiter, Liste, Meldung. Keine
   Technik- oder Fachwörter der Entwicklung.
5. Der Text funktioniert **ohne Bild**. Wo die Bedienung je Modus abweicht,
   nennt er beide Wege, den des Barrierefrei-Modus zuerst – z. B. „Wählen Sie
   **Zurück zur Liste** (ohne Barrierefrei-Modus: **Schließen**)."
6. Der letzte Schritt sagt, woran man den Erfolg erkennt (Meldung wörtlich).
   Hinweise oben im Mitglied sind keine Fehler – wo sie typisch auftreten,
   kurz sagen, dass gespeichert wurde und was der Hinweis bedeutet.
7. Bilder nur, wo sie beim Zurechtfinden helfen: meist beim ersten Schritt
   einer neuen Seite und beim Ergebnis; **höchstens 4 Bilder** je Anleitung.
   Der Alternativtext sagt in einem Satz, was zu sehen und was markiert ist.
8. Nur was es gibt und was die Rolle aus `recht` darf. Namen in Texten und
   Bildern nur aus den Beispieldaten (§5).

## 5. Beispieldaten und Demo-Zugänge (nur lokal)

`tools/hilfe-beispieldaten.php` (nicht im Release) richtet die **lokale**
Docker-Umgebung für die Bilder ein. Es verweigert sich, wenn die
Konfiguration nicht `debug => true` hat, und nutzt kein `exec`.

- Löscht alle Mitglieder und Familien und legt neu an: etwa 30 erfundene
  Mitglieder (Nummern ab 1001, E-Mail `@example.org`, Test-IBANs mit
  gültiger Prüfziffer), 4–5 Familien, Abteilungen, Funktionen und
  Beitragsarten aus Migration 040, Vereinsdaten „SV Musterstadt e. V.".
  Geburtstage **relativ zum heutigen Tag**, damit die Geburtstagsliste in
  Woche und Monat Einträge hat (darunter ein runder).
- Zwei Zugänge der Rolle **Vorstand**: `hilfe-normal@example.org` (Modus
  aus) und `hilfe-barrierefrei@example.org` (Modus an), zweiter Faktor
  **Authenticator-App** mit festem Demo-Geheimnis. Passwort und Geheimnis
  stehen in `tools/hilfe-screenshots/konten.json` (erfundene Werte nur für
  die lokale Umgebung). Für Bilder, die ein anderes Recht brauchen, darf das
  Skript weitere Zugänge anlegen: `admin-normal` und `admin-barrierefrei`
  (Rolle **Admin**) für `mitglied-loeschen`.
- Zwei Ehemalige: Nr. 1031 für „Ehemalige anzeigen", Nr. 1032 nur für
  `mitglied-loeschen`, dessen Aufnahme ihn löscht.
- Am App-Code ändert sich für die Anmeldung **nichts** (keine Hintertür):
  das Skript rechnet den App-Code selbst aus (RFC 6238, wie
  `App\Service\Account\Totp`).

## 6. Screenshot-Skript und Bilder

`tools/hilfe-screenshots/` (Node + das installierte Playwright, nicht im
Release). Ein Befehl (`tools/hilfe-screenshots/start.sh`, README) startet
ein eigenes Docker-Projekt (`--projekt <name>`, Standard `hilfe`) mit
**freiem Host-Port** statt 8080 (Compose-Override, Port per
`docker compose port`), migriert, legt die Beispieldaten an, nimmt erst
alle Anleitungen im Modus normal, dann – nach erneutem Anlegen der Daten –
im Barrierefrei-Modus auf und räumt das Projekt ab.

- **Aufnahme-Datei** je Anleitung: `tools/hilfe-screenshots/anleitungen/<slug>.mjs`
  ```js
  export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await markiere(seite.getByRole('link', { name: 'Neues Mitglied' }));
    await foto(1);                       // Bild zu Schritt 1
    // …
  }
  ```
  Läuft angemeldet als passender Demo-Zugang; `foto(n)` speichert Schritt
  `n` im aktuellen Modus. Bedienelemente über Rolle und **deutsche
  Beschriftung** finden (`getByRole`, `getByLabel`), Mitglieder über ihre
  Nummer – so fällt eine geänderte Oberfläche sofort auf.
- **Abgleich:** Die Nummern aus `foto()` müssen genau die Schritte mit
  `bild` sein (JSON aus §2, je Anleitung `slug`, `titel`, `recht` und
  `bilder` = Liste der Schrittnummern mit `bild`); sonst bricht das Skript
  ab. Dazu gehört: jede Anleitung mit Bildern braucht eine Aufnahme-Datei,
  jede Aufnahme-Datei eine Anleitung.
- **Zugang und Probe:** Eine Aufnahme darf `export const zugang = 'admin'`
  setzen (Konto `admin-normal` / `admin-barrierefrei` in `konten.json`) und
  mit `export const probe = true` als Probe ohne Anleitung laufen (Bilder
  nach `tools/hilfe-screenshots/probe-ausgabe/`, nicht in git).
  `anleitungen/beispiel.mjs` ist so eine Probe. Das Skript legt die Daten nur
  zwischen den Modi neu an; Aufnahmen, die etwas ändern, stellen den
  Ausgangszustand selbst wieder her.
- **Markieren:** ein kräftiger Rahmen um das gemeinte Element (nur im
  Browser des Skripts eingefügt; dafür `bypassCSP` nur dort). Ist das
  Element verdeckt (feste Speichern-Leiste, Karte), scheitert die Aufnahme.
- **Kartenkacheln** beantwortet der Browser des Skripts mit einer
  einfarbigen Kachel – die Bilder hängen nicht vom Kachel-Server ab (#119).
- **Bilder:** PNG, Fenster 1280 × 800, `deviceScaleFactor` 1, `de-DE`,
  `Europe/Berlin`, helles Farbschema, keine Animationen. Je Bild höchstens
  250 KB, alle Bilder zusammen höchstens 10 MB (sie gehen in jedes
  Release-ZIP).
- **`--pruefen`:** führt alle Abläufe aus, schreibt keine Bilder und
  scheitert bei fehlendem Element (kurze Wartezeit), HTTP-Fehler einer Seite,
  Fehler in der Browser-Konsole oder fehlendem Abgleich. `--nur <slug>`
  beschränkt auf eine Anleitung. Damit prüfen die Anleitungs-Issues ihre
  Texte gegen die laufende Anwendung.
- Bilder werden nur im Abschluss-Issue (#119) bzw. nach späteren
  Oberflächen-Änderungen neu erzeugt und committet, nicht in jedem PR.

## 7. Pflicht-Tests

Gerüst (#114):
- Laden der Anleitungen mit Beispiel-Dateien aus `tests/Fixtures/hilfe/`:
  Reihenfolge, Slug aus dem Dateinamen, jede Regel aus §2 einzeln als Fehler
  (unbekannter Schlüssel, fehlender Titel, falsches Recht, keine oder zu
  viele Schritte, leerer Text, leeres `bild`, falscher Dateiname),
  Rollenfilter für Admin, Vorstand, Nur lesen.
- Alle echten Dateien unter `app/data/hilfe/` sind gültig (läuft auch, wenn
  es noch keine gibt).
- Seiten: abgemeldet → Anmeldung; Übersicht für alle drei Rollen 200; nicht
  erlaubte und unbekannte Anleitung 404; Barrierefrei-Konto bekommt
  `-barrierefrei.png`, sonst `-normal.png`; fehlende Bilddatei → kein
  `<img>`; Schritte als `<ol>`; Text wird escaped; kein `style=`.
- Zugriffsmatrix `tests/Http/routen-zugriff/hilfe.txt`, Benutzermenü
  (`NavigationTest`), Musterseite (`DesignsystemTest`).

Skript (#115), `node --test` (reine Logik, Dateien unter `tests/js/`):
App-Code gegen die Testvektoren aus RFC 6238, Bildnamen, Abgleich
Anleitung ↔ Aufnahme. PHPUnit: `tools/hilfe-beispieldaten.php` verweigert
sich ohne `debug`.

Abschluss (#119):
- Jeder Schritt mit `bild` hat beide Bilder; keine verwaisten Bilder unter
  `public/img/hilfe/`; echte PNG-Dateien; Größengrenzen aus §6.
- Rollen mit den echten Anleitungen: Admin sieht alle, Vorstand alle außer
  `mitglied-loeschen`, Nur lesen genau `anmelden`, `mitglied-suchen`,
  `barrierefrei-modus`, `geburtstagsliste`, `statistik`, `app-installieren`.

## 8. Die Anleitungen

| Datei | Titel | Recht | Issue |
|---|---|---|---|
| `01-anmelden` | Anmelden und abmelden | – (`null`) | #116 |
| `02-mitglied-suchen` | Mitglied suchen und öffnen | `MitgliederSehen` | #117 |
| `03-mitglied-anlegen` | Neues Mitglied anlegen | `MitgliederBearbeiten` | #117 |
| `04-anschrift-aendern` | Anschrift, Telefon oder E-Mail ändern | `MitgliederBearbeiten` | #117 |
| `05-beitragsart-aendern` | Beitragsart, Zahlart oder IBAN ändern | `MitgliederBearbeiten` | #118 |
| `06-abteilung-zuordnen` | Mitglied einer Abteilung zuordnen | `MitgliederBearbeiten` | #118 |
| `07-austritt-eintragen` | Austritt eintragen | `MitgliederBearbeiten` | #118 |
| `08-barrierefrei-modus` | Barrierefrei-Modus ein- und ausschalten | – (`null`) | #116 |
| `09-geburtstagsliste` | Geburtstagsliste anzeigen und exportieren | `ListenSehen` | #116 |
| `10-mitglied-loeschen` | Ehemaliges Mitglied löschen | `MitgliederLoeschen` (nur Admin) | #129 |
| `11-statistik` | Statistik ansehen | `ListenSehen` | #129 |
| `12-app-installieren` | Als App auf Handy oder PC installieren | – (`null`) | #129 |

`12-app-installieren` beschreibt den Browser, nicht unsere Oberfläche, und
hat deshalb keine Bilder und keine Aufnahme-Datei; die Browser-Menünamen
bleiben allgemein (Schreibregel 2 gilt für die eigene Oberfläche).
`11-statistik`: der Reiter „Beiträge" bleibt dem Admin vorbehalten und
steht nur als Tipp im Text.

Für `01-anmelden` dürfen die Bilder die Anmeldung mit Authenticator-App
zeigen (Demo-Zugang); der Text erklärt den Code per E-Mail als Standard und
die App als Alternative.

## 9. Abschlussprüfung (#119)

- [ ] Jedes Bild angesehen: nur Beispieldaten, keine Fehlermeldung oder
      Debug-Ausgabe, markiert ist das im Text gemeinte Element,
      Barrierefrei-Bilder zeigen den Modus.
- [ ] Jede Anleitung gegen §4 gelesen.
- [ ] Hilfe als Admin, Vorstand und Nur lesen durchgegangen: 360 px, PC,
      PC im Barrierefrei-Modus nur mit Tastatur.
- [ ] PHPUnit, `node --test` und `start.sh --pruefen` grün.
- [ ] Größe aller Bilder unter der Grenze aus §6.
