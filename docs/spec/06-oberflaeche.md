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

Sieben Bereiche in der Hauptnavigation, mehr nicht („Karte" kam mit #91,
„Familien" mit #92 dazu). Was zusammengehört, liegt auf **einer** Seite und wird dort
umgeschaltet statt verlinkt.

```
┌──────────────────────────────────────────────────────────────────────┐
│ Mitglieder  Familien  Listen  Karte  Beiträge*  Einstellungen*  [Ben.]│
└──────────────────────────────────────────────────────────────────────┘
* nur Admin
```

1. **Mitglieder** (Startseite)
   - Suchfeld (Name, Anschrift, Kontakt, IBAN, Geburtsdatum …, 07 §8)
     mit Live-Ergebnis und einer Beschreibung, wonach gesucht wird
     (`aria-describedby`), darunter die Liste; einfache Filter
     (Abteilung, aktiv/passiv, Beitragsart, Ehemalige anzeigen). „Je
     Seite" (50, 100, 200, Alle) steht in der Blätterleiste unter der Liste.
   - Klick auf ein Mitglied öffnet es **auf derselben Seite** (am PC
     rechts neben der Liste, am Handy als eigene Ansicht mit „Zurück").
     Das geöffnete Mitglied ist ein Baustein, den andere Seiten (Listen,
     Familien) genauso einbetten können (§5).
     Ohne geöffnetes Mitglied hat die Liste am PC die ganze Breite; am PC
     schließen „Schließen" im Kopf des Mitglieds oder ein zweiter Klick
     auf seinen Namen in der Liste es wieder.
   - Mitglied mit Reitern in dieser Reihenfolge: **Daten** (alle Felder,
     ein Formular), **Familie**, **Beiträge** (Jahresbeiträge, Status –
     Admin), **Nachweise**, **Verlauf** (Versionen, zurückholen).
     Hinweise („Rentner unter 67") stehen oben im Mitglied, über den
     Reitern; beim Hinweis „Andere Anschrift" steht die Anschrift des
     Zahlers daneben, und ein Knopf trägt sie ins Formular „Daten" ein
     (gespeichert erst mit dem Formular).
   - „Neues Mitglied" öffnet dasselbe Formular leer.
   - Familien werden im Mitglied gepflegt (Familie wählen/anlegen, Rolle);
     der Reiter „Familie" verlinkt „In Familien öffnen".
2. **Familien** (#92, alle Rollen sehen, `mitglieder.bearbeiten` ändert) –
   links die Liste aller Familien (Name, Zahler, Personen, Etikett
   „Anschriften weichen ab"), Suche nach dem Namen der Familie oder einer
   Person (nur im POST-Body), darunter „Neue Familie". Klick öffnet rechts
   die Familie: Personen mit Rolle und Anschrift (abweichende mit
   „weicht ab"), Hinweise (zu klein, kein/mehrere Zahler, Anschriften), je
   Person Rolle ändern und „Aus der Familie nehmen", „Zahler wechseln",
   „Anschriften vereinheitlichen", „Person hinzufügen" (Suche), umbenennen,
   eine leere Familie löschen. Eine Person öffnet sich **an Stelle der
   Familie** als Baustein (§5) mit „Zurück zur Familie". URL:
   `/app/familien?familie=…&mitglied=…&reiter=…`. Am Handy nacheinander
   Liste → Familie → Mitglied, je mit Zurück-Link.
3. **Listen** – eine Seite, die sofort mit den Geburtstagen öffnet; oben
   die Listen als Reiter (echte Links, `aria-current`):
   Geburtstage · Ehrungen · Funktionen · Selbst kassieren ·
   Auffälligkeiten · BLSV-Meldung · Alle Mitglieder (Gesamtexport), je
   nach Recht. Jede Liste hat ihre wenigen Filter (z. B.
   Woche/Monat/Jahr, nur runde) und **einen** Export-Knopf (xlsx oder csv).
   Klick auf eine Person öffnet sie **in der Listenseite selbst** (Baustein
   aus §5, am PC rechts neben der Liste); Filter und Zeitraum bleiben in
   der URL, es gibt keinen Wechsel auf die Mitglieder-Seite.
4. **Karte** (#91, beide Rollen, `mitglieder.sehen`) – die Wohnorte der
   aktuellen Mitglieder auf einer OpenStreetMap-Karte (Leaflet, vendored).
   Oben der Stand („x von y Anschriften gefunden"), für den Admin der Knopf
   „Jetzt Koordinaten ermitteln", ein einfacher Filter (Abteilung,
   aktiv/passiv, per GET). Ein Marker je Anschrift mit der Zahl der
   Mitglieder dort; Klick bzw. Enter zeigt Namen und Nummern mit Link zum
   Mitglied. Darunter dieselben Anschriften als Tabelle (die Marker
   entstehen aus deren Zeilen), dazu „Nicht gefundene Anschriften",
   „Noch nicht ermittelt" und „Ohne Anschrift" mit Links – damit ist die
   Seite ohne Karte und im Barrierefrei-Modus vollständig nutzbar.
   Hinweis „© OpenStreetMap-Mitwirkende" auf der Karte. Keine Änderung an
   der Datenschutzerklärung (mit Mirko abgestimmt).
5. **Beiträge** (Admin) – eine Seite mit dem Ablauf des Jahres:
   Jahresbeiträge erzeugen → Einzug zusammenstellen → Lastschriftdatei
   herunterladen → Einzug bestätigen. Darunter die offenen Beiträge mit
   „bezahlt setzen".
6. **Einstellungen** (Admin) – eine Seite mit Abschnitten: Verein
   (Vereinsdaten, Bank, Gläubiger-ID, Verbände, Logo – 11), Abteilungen, Beitragsarten, Funktionen, runde
   Geburtstage, Ehrungsstufen, Benutzer, Import, System (Update, Backup,
   Systemcheck – übernommen).
7. **Benutzermenü** (Name oben rechts): eigenes Passwort, zweiter Faktor,
   **Barrierefrei-Modus an/aus**, Abmelden.

Was die Rolle nicht darf, wird nicht angezeigt **und** serverseitig
abgelehnt (02 §6).

## 3. Muster

- Listen: Tabelle am PC, Karten untereinander am Handy; Sortierung durch
  Klick auf die Spaltenüberschrift; bei 700 Mitgliedern genügt Blättern
  in Seiten zu 50; die Mitgliederliste bietet dazu „Je Seite" bis „Alle"
  (Parameter `anzahl`, `alle` = eine Seite). Das Auswahlfeld sitzt in der
  Blätterleiste zwischen „Vorherige Seite" und „Nächste Seite", neben „Seite
  x von y", und gehört per `form`-Attribut zum Suchformular: mit htmx wirkt
  die Änderung sofort, ohne Skript der Knopf „Übernehmen". Die Leiste
  erscheint auch bei nur einer Seite, sobald eine größere Anzahl gewählt ist
  (sonst käme man von „Alle" nicht zurück).
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
sofort auf allen Seiten. Der Admin kann sie beim Einladen voreinstellen
(das Konto ist dann ab dem ersten Login im Modus) und bei bestehenden
Konten in der Benutzerverwaltung ein- oder ausschalten (02 §5, #87). Ausgelegt auf den **PC**; am Handy ändert er
nichts Wesentliches.

| Bereich | Im Modus |
|---|---|
| Schrift | deutlich größer (Grundschrift ~150 %), größere Zeilenabstände |
| Kontrast | sehr hoher Kontrast, kein grauer Text, deutliche Rahmen um Felder und Knöpfe; heller Hintergrund unabhängig vom Dunkelmodus |
| Bedienflächen | große Knöpfe und Klickflächen, Knöpfe immer mit Text, keine reinen Symbole |
| Aufbau | Mitglied öffnet sich unter der Liste statt daneben, seine Überschrift bekommt den Fokus (§5); Formulare einspaltig; Listen mit weniger Spalten und größeren Zeilen |
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

## 5. Mitglied als Baustein (#86)

Ein geöffnetes Mitglied (Kopf mit Name, Nummer, Status und „Schließen",
Hinweise, Reiter) ist **ein** Baustein: `App\App\Mitglied\MitgliedAnsicht`
mit dem Template `app/views/mitglied/ansicht.php`. Eine Seite mit einer
Personenliste bettet ihn rechts neben ihrer Liste ein (Muster „Liste neben
Mitglied", `geteilt`). Heute tun das die Mitglieder-Seite, die Familien
(#92) und die Listen (#93, `ListenController`, Template
`app/views/listen/bereich.php`). Was im Mitglied auf eine eigene
Seite führt (Familie wählen, Löschen-Rückfrage, ein Jahresbeitrag), führt
von dort auf die einbettende Seite zurück, nie auf die Mitglieder-Seite.

**Einbetten** – drei Schritte im Controller, einer im Template:

```php
// 1. Wo das Mitglied steht: eigene Adresse + eigener Zustand (Filter,
//    Sortierung – nie ein Suchtext), mit htmx: id des getauschten Bereichs.
$einbettung = new Einbettung('/app/listen/geburtstage', ['zeitraum' => 'monat'])
    ->mitHtmx('listen-bereich');            // ohne: gewöhnliche Links
// 2. Das Mitglied aus der Anfrage (mitglied, reiter, fokus aus Query/Body).
$ansicht = MitgliedAnsicht::aus($dienste)->oeffnen($request, $einbettung);
// 3. Antwort: bei HX-Target = 'listen-bereich' nur diesen Bereich rendern.
```

```php
<div class="geteilt<?= $ansicht->klasse() ?>" id="listen-bereich">
    <div class="geteilt-liste">
        … <a <?= $einbettung->link($einbettung->adresse($zeile->id), 'mitglied') ?>>Name</a> …
    </div>
    <?= $ansicht->html ?>
</div>
```

- **Adressen:** `$einbettung->adresse($id, $reiter)` =
  `/app/listen/geburtstage?zeitraum=monat&mitglied=12&reiter=daten`;
  `rueckweg()` = dieselbe Seite ohne Mitglied („Schließen"). Ein zweiter
  Klick auf das geöffnete Mitglied verlinkt `rueckweg()` (schließt).
  `$ansicht->unbekannt` meldet eine gelöschte Person (Hinweis über der
  Liste, kein Fehler).
- **htmx:** `link()` tauscht den Bereich `ziel` (outerHTML) und schreibt
  die Adresse in den Verlauf; mit `mitHtmx($ziel, '#formular')` per POST
  samt diesem Formular (die Mitglieder-Seite, weil ihr Suchtext nur im
  Body reist), sonst per GET. Ohne Skript sind es normale Links.
- **Formulare und Weiterleitungen:** Die Formulare des Mitglieds posten an
  ihre eigenen Routen (`/app/mitglieder/{id}/…`) und tragen die Seite im
  versteckten Feld `zurueck` (`$einbettung->feld()`), eigene Seiten
  (Familie wählen, Löschen-Rückfrage) im Query (`mitRueckweg()`). Nach dem
  Speichern leitet die Route dorthin zurück, auf den Reiter, von dem das
  Formular kam (`Einbettung::ausAnfrage()`); nach dem Löschen auf die
  Seite ohne Mitglied. `zurueck` wird nur als Pfad unter `/app` mit
  einfachem Query angenommen (`Einbettung::istSicher()`, kein Open
  Redirect), sonst gilt die Mitglieder-Seite.
- **Reiter und Abschnitte** bekommen zu ihren eigenen Daten
  `$einbettung`, `$mitgliedId` und `$offenerReiter`; wer einen neuen
  schreibt, baut Links und Formulare damit statt mit festen Adressen.
- **Fokus:** Öffnet ein Link das Mitglied (`link(…, 'mitglied')`) oder
  lädt die ganze Seite mit Mitglied ohne Meldung oben (nach dem Speichern
  bleibt die Meldung im Blick, der Fokus am Seitenanfang), bekommt
  seine Überschrift den Fokus (`autofocus` an `h2 tabindex="-1"`, htmx
  fokussiert nach dem Tausch); ein Reiterwechsel (`'reiter'`) fokussiert
  den gewählten Reiter; andere Links (Sortieren) lassen den Fokus, wo er
  ist.
- **Rechte** bleiben serverseitig je Aktion: Reiter und Abschnitte
  erscheinen nur mit ihrem Recht, jede Route prüft ihr eigenes. Die
  einbettende Seite braucht selbst mindestens `MitgliederSehen`.
- **An Stelle statt daneben:** Steht das Mitglied nicht neben der Liste,
  sondern ersetzt es etwas (die Familien: die geöffnete Familie), setzt die
  Seite `->mitZurueck('Zurück zur Familie')`. Dann führt ein eigener
  Zurück-Link mit diesem Text auch am PC zurück (statt „Schließen"); er
  fragt mit `fokus=zurueck` nach dem Fokus, den die Seite auf ihre eigene
  Überschrift legt.
- **Barrierefrei-Modus (PC):** Das Mitglied steht unter der Liste (eine
  Spalte, kräftige Linie davor), „Schließen" schließt es; am Handy wie
  sonst eine eigene Ansicht mit „Zurück zur Liste".

## Pflicht-Tests

`CspComplianceTest` (kein Inline-JS, kein `hx-on`), Routen-Zugriffsmatrix
inkl. „Vorstand sieht keine Beiträge", Barrierefrei-Modus wird aus der
Benutzereinstellung gesetzt und überlebt das Neuladen; Mitglied als
Baustein mit einer zweiten einbettenden Seite (Reiter, Speichern-
Weiterleitung, Schließen, Löschen zeigen auf diese Seite; die Listenseite
öffnet Personen selbst – `ListenMitgliedTest`; unsicheres
`zurueck` fällt auf die Mitglieder-Seite zurück; Fokus) –
`MitgliedAnsichtTest`, `EinbettungTest`; Familien-Seite (#92) mit dem
Mitglied an Stelle der Familie, „Zurück zur Familie", Fokus –
`FamilienSeiteTest`; manuelle Prüfliste
je UI-PR: 360 px Handy, PC, PC im Barrierefrei-Modus (nur Tastatur).
