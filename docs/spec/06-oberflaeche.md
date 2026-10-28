# 06 – Oberfläche

Entscheidung E-05: **server-gerenderte PHP-Seiten + htmx**, wie
Vereinsbelege. Login, 2FA, Installer, Updater, Backup, Systemcheck und
Benutzerverwaltung werden samt Oberfläche übernommen; das Aussehen wird
eigenständig nach dem Skill `frontend-design` gestaltet.

## 1. Grundsätze

- **Einfach vor allem anderen.** Wenige Seiten, wenige Knöpfe, klare
  Wörter. Lieber eine Liste mit Filter als eine eigene Seite je Zweck.
- **Mobil und PC.** Jede Seite funktioniert auf dem Handy (ab 360 px) und
  am PC. Auf dem Handy reicht das Wichtigste: suchen, ansehen, anlegen,
  ändern. Einzug, Import und Einstellungen dürfen am Handy schlicht
  aussehen.
- **Barrierearmut im Normalbetrieb nur als solide Grundlage**:
  semantisches HTML (echte Überschriften, Beschriftungen an jedem Feld,
  Buttons mit Text), sichtbarer Tastaturfokus, ausreichender Kontrast.
  Kein vollständiger Standard (WCAG) als Pflicht.
- **Barrierefrei-Modus** für Benutzer, die ihn brauchen (§4).
- Technik: htmx vendored, über `<meta name="htmx-config">` konfiguriert
  (`includeIndicatorStyles: false`), kein `hx-on`, kein Inline-JS; ein
  handgeschriebenes Stylesheet mit Farbtokens (hell/dunkel), kein
  CSS-Build. Vorlage: `public/css/app.css` und `public/js/vendor/` aus
  Vereinsbelege.

## 2. Seitenaufbau (Vorschlag)

Fünf Bereiche in der Hauptnavigation, mehr nicht. Was zusammengehört,
liegt auf **einer** Seite und wird dort umgeschaltet statt verlinkt.

```
┌──────────────────────────────────────────────────────────────┐
│ Mitglieder   Listen   Beiträge*   Einstellungen*   [Benutzer]│
└──────────────────────────────────────────────────────────────┘
* nur Admin
```

1. **Mitglieder** (Startseite)
   - Suchfeld (Name, Mitgliedsnummer, Ort) mit Live-Ergebnis, darunter
     die Liste; einfache Filter (Abteilung, aktiv/passiv, Beitragsart,
     Ehemalige anzeigen).
   - Klick auf ein Mitglied öffnet es **auf derselben Seite** (am PC
     rechts neben der Liste, am Handy als eigene Ansicht mit „Zurück").
   - Mitglied mit Reitern: **Daten** (alle Felder, ein Formular),
     **Beiträge** (Jahresbeiträge, Status – Admin), **Verlauf**
     (Versionen, zurückholen). Hinweise („Rentner unter 67") stehen oben
     im Mitglied.
   - „Neues Mitglied" öffnet dasselbe Formular leer.
   - Familien werden im Mitglied gepflegt (Familie wählen/anlegen, Rolle).
2. **Listen** – eine Seite, oben eine Auswahl:
   Geburtstage · Ehrungen · Funktionen · Selbst kassieren ·
   Auffälligkeiten · BLSV-Meldung · Alle Mitglieder (Gesamtexport).
   Jede Liste hat ihre wenigen Filter (z. B. Woche/Monat/Jahr, nur runde)
   und **einen** Export-Knopf (xlsx oder csv).
3. **Beiträge** (Admin) – eine Seite mit dem Ablauf des Jahres:
   Jahresbeiträge erzeugen → Einzug zusammenstellen → Lastschriftdatei
   herunterladen → Einzug bestätigen. Darunter die offenen Beiträge mit
   „bezahlt setzen".
4. **Einstellungen** (Admin) – eine Seite mit Abschnitten: Verein
   (Vereinsdaten, Bank, Gläubiger-ID, Verbände, Logo – 11), Abteilungen, Beitragsarten, Funktionen, runde
   Geburtstage, Ehrungsstufen, Benutzer, Import, System (Update, Backup,
   Systemcheck – übernommen).
5. **Benutzermenü** (Name oben rechts): eigenes Passwort, zweiter Faktor,
   **Barrierefrei-Modus an/aus**, Abmelden.

Was die Rolle nicht darf, wird nicht angezeigt **und** serverseitig
abgelehnt (02 §6).

## 3. Muster

- Listen: Tabelle am PC, Karten untereinander am Handy; Sortierung durch
  Klick auf die Spaltenüberschrift; bei 700 Mitgliedern genügt Blättern
  in Seiten zu 50.
- Formulare: einspaltig am Handy, zweispaltig am PC; Speichern-Knopf
  immer sichtbar; Hinweise erscheinen nach dem Speichern oben, blockieren
  nie.
- Texte nach dem Skill `frontend-design` (Abschnitt „Writing"): Knöpfe
  sagen, was passiert („Mitglied speichern", nicht „Absenden"), Fehler
  sagen, was zu tun ist.
- **Musterseite `/admin/designsystem`** (nur Admin, #9) zeigt jeden
  Baustein aus `public/css/app.css` mit erfundenen Beispieldaten; neue
  Seiten kopieren ihr Markup von dort statt eigene Klassen zu erfinden.
  `?barrierefrei=1` zeigt sie im Barrierefrei-Modus, ohne die Einstellung
  des Benutzers zu ändern. Die Bausteine: Knöpfe (`knopf`, `knopf-haupt`,
  `knopf-gefahr`, `knopf-still`), Meldungen (`hinweis-*`), Etiketten
  (`etikett-*`), Formular (`formular`, auf langen Formularen
  `formular-zweispaltig`, `feld`, `feld-hinweis`, `feld-fehler`,
  `feld-haken`, `formular-aktionen` = Speichern-Leiste, die am unteren
  Rand klebt), Suche und Filter (`suche`, `filter`), Liste (`liste` –
  Tabelle am PC, Karten am Handy über `data-label` je Zelle;
  `nebenspalte` entfällt neben dem Detail und im Barrierefrei-Modus),
  Blättern (`blaettern`), Leerzustand (`leer`), Reiter (`reiter`, Links mit
  `aria-current="page"`), Liste neben Detail (`geteilt`, vom Server mit
  `geteilt-mit-detail` markiert, ohne Skript), Angaben (`angaben`),
  Ladeanzeige (`htmx-indicator`, `laedt`).
- Schrift: **Atkinson Hyperlegible Next** (Braille Institute, OFL),
  selbst ausgeliefert unter `public/css/schrift/`; Logo in der Kopfzeile
  über `App\View\View::setLogo()`, ohne Upload ein neutrales Wappen
  (11 §2).

## 4. Barrierefrei-Modus

Einstellung **je Benutzer** (Benutzermenü), gespeichert am Benutzer, wirkt
sofort auf allen Seiten. Ausgelegt auf den **PC**; am Handy ändert er
nichts Wesentliches.

| Bereich | Im Modus |
|---|---|
| Schrift | deutlich größer (Grundschrift ~150 %), größere Zeilenabstände |
| Kontrast | sehr hoher Kontrast, kein grauer Text, deutliche Rahmen um Felder und Knöpfe; heller Hintergrund unabhängig vom Dunkelmodus |
| Bedienflächen | große Knöpfe und Klickflächen, Knöpfe immer mit Text, keine reinen Symbole |
| Aufbau | Mitglied öffnet sich als ganze Seite statt neben der Liste; Formulare einspaltig; Listen mit weniger Spalten und größeren Zeilen |
| Bewegung | keine Animationen, nichts erscheint nur beim Darüberfahren mit der Maus |
| Tastatur | vollständig mit Tastatur bedienbar, sehr deutlicher Fokusrahmen |
| Zeit | Leerlaufzeit der Sitzung 60 statt 30 Minuten, Warnung mit „Weiterarbeiten" vor dem Abmelden |
| Rückfragen | vor folgenreichen Aktionen eine klare Rückfrage in ganzen Sätzen |

Umsetzung einfach gehalten: eine Klasse am `<html>`-Element
(`modus-barrierefrei`), die die Farb-, Größen- und Abstands-Tokens
überschreibt, plus wenige Layout-Regeln. Kein zweites Layout, keine
zweiten Templates. Die große Schrift greift ab PC-Breite (48rem); am Handy
wächst sie nur leicht, Kontrast und Rahmen gelten überall. Die Punkte
Zeit (Leerlauf, Warnung) und Rückfragen setzt #10 um.

## Pflicht-Tests

`CspComplianceTest` (kein Inline-JS, kein `hx-on`), Routen-Zugriffsmatrix
inkl. „Vorstand sieht keine Beiträge", Barrierefrei-Modus wird aus der
Benutzereinstellung gesetzt und überlebt das Neuladen; manuelle Prüfliste
je UI-PR: 360 px Handy, PC, PC im Barrierefrei-Modus (nur Tastatur).
