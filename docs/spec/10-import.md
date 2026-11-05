# 10 – Import und Abgleich mit der alten Software

Quelle: `docs/fachlichkeit.md` §8. Grundsatz: **nichts zaubern**. Die
Anwendung kennt genau ein eigenes Importformat. Die Altdaten der
bisherigen Software werden **lokal** mit einem Skript in dieses Format
gebracht.

Weil der Übergang länger dauern kann, gibt es zwei Arten:
- **Erstimport** (§1) in einen leeren Bestand,
- **Abgleich** (§4) mit einer späteren Datei aus der alten Software:
  Merge-Ansicht, Mirko wählt aus, was übernommen wird.

## 1. Ablauf

1. Mirko exportiert aus der alten Software die **Mitgliederdatei** (alle
   Spalten) und die **Familiendatei** (`FamID`, `AdrNr`) als xlsx.
2. Daraus entsteht `import.csv` im Importformat (§2) – auf Mirkos
   Rechner, die Dateien verlassen ihn nicht (§3).
3. In der Anwendung: Einstellungen → Import → `import.csv` hochladen.
4. **Vorschau**: Anzahl Mitglieder, Familien, Abteilungen; Liste der
   Zeilen mit Hinweisen (07 §6) und mit Fehlern (z. B. doppelte
   Mitgliedsnummer, unbekannte Abteilung).
5. **Importieren** als Schrittkette (je Schritt ~100 Zeilen, 01 §4). Jedes
   Mitglied bekommt Version 1 mit Anlass `importiert`.
6. Danach Prüflisten: Auffälligkeiten (09) – darunter Elternteile in
   Familien (könnten volljährige Kinder sein), aktiv/passiv bei Familien.

- Erstimport nur in einen **leeren Mitgliederbestand**. Für Probeläufe
  gibt es „Bestand leeren" – nur solange noch kein Jahresbeitrag erzeugt
  wurde, mit Rückfrage. Ist der Bestand nicht leer, bietet die Seite
  stattdessen den Abgleich an (§4).
- Importierte Mitglieder tragen die Herkunft `import`, Familien ihren
  Schlüssel aus der alten Software (`FamID`) – beides braucht der
  Abgleich.
- Es werden **keine Jahresbeiträge** importiert; die wenigen offenen
  Posten der alten Software werden von Hand nachgetragen.
- Abteilungen, Beitragsarten und Funktionen kommen aus den Seeds
  (07 §2/§4, 08 §1); der Import ordnet über Name bzw. BLSV-Nummer zu.

**Umsetzung Erstimport (#32):** Abschnitt „Import“ der Einstellungen
(`App\Admin\Einstellungen\ImportAbschnitt`, Recht `admin.import`, Routen in
`app/routes/import.php`, Dienst `App\Service\Import\ErstImport`).
- Hochgeladen wird `import.csv` (höchstens 8 MB). Die Datei liegt bis zum
  Ende des Imports **verschlüsselt als Blob**; die Job-Zeile (`job`, Typ
  `import`) hält nur Position und Zähler (`phase` = `vorschau` | `import` |
  `fertig`, `offset`, `gesamt`), nie Mitgliederdaten. Nach dem Import, beim
  Verwerfen und beim Leeren wird die Datei gelöscht.
- `ImportLeser` liest und prüft die ganze Datei auf einmal und meldet alle
  Fehler zusammen (Zeilennummer, Mitgliedsnummer, Text): fehlende/ungültige/
  doppelte Nummer, fehlender Nachname, unbekannte Abteilung (BLSV-Nummer,
  „9“ = „09“), Beitragsart oder Funktion (Name, ohne Groß-/Kleinschreibung),
  kaputtes Datum, ungültiges Geschlecht/`aktiv`/Zahlart, Familie ohne Rolle
  und umgekehrt, zu lange Werte, falsche Spaltenzahl. Mit Fehlern wird
  **nichts** importiert, auch nicht die fehlerfreien Zeilen.
- `land`: deutscher Name, ISO-Code oder altes Kfz-Kennzeichen (gespeichert wird ISO); ein unbekannter Code mit 1–3 Zeichen (Altdaten, z. B. `CDN`) wird unverändert übernommen (am Mitglied dann der Hinweis „Land unbekannt", 07 §6), ein längerer unbekannter Wert passt nicht in die Spalte = Fehlerzeile. Leere `land` = `DE`, leere `zahlart` = `lastschrift` (wie beim neuen
  Mitglied), leere `aktiv` = aktiv. Eine Familie ohne `familie_name` heißt
  „Familie <Nachname des ersten Mitglieds>“.
- Die Vorschau zeigt Mitglieder, Familien, Mitglieder je Abteilung, die
  Fehler und je Mitglied die Hinweise (07 §6, mit den Familien der Datei).
  **Nachweis-Hinweise** (Rentennachweis fehlt, Jugendbeitrag ab 18, Nachweis
  abgelaufen) fehlen dort bewusst: Nachweise werden nicht importiert, sonst
  stünde jeder Rentner in der Liste.
- Schritt = 100 Zeilen in einer Transaktion; die Position wird erst nach dem
  Commit gespeichert. Wiederholung ist unschädlich: eine vorhandene
  Mitgliedsnummer wird übersprungen, eine Familie zuerst per `import_schluessel`
  gesucht. Der Schritt hält eine Sperre (25 s); ein abgestürzter Schritt
  wird nach Ablauf der Sperre vom nächsten Request wiederholt.
- „Bestand leeren“ (eigene Rückfrageseite): löscht alle Mitglieder samt
  Versionen, Nachweisen (und Dateien), Ehrungs-Vermerken und alle Familien;
  nur ohne Jahresbeitrag. Audit-Einträge: `import.durchgefuehrt` (nur Zahlen)
  und `bestand.geleert`.

## 2. Importformat `import.csv`

UTF-8, Trennzeichen `;`, erste Zeile Spaltennamen, Datum `TT.MM.JJJJ`,
leere Zelle = kein Wert. Eine Zeile je Mitglied.

| Spalte | Inhalt |
|---|---|
| `mitgliedsnummer` | Pflicht, ganze Zahl |
| `titel`, `vorname`, `nachname`, `namenszusatz` | Text |
| `geschlecht` | `m`/`w`/`d` |
| `geburtsdatum`, `eintrittsdatum`, `austrittsdatum` | Datum; Eintritt leer = unbekannt |
| `austrittsgrund` | Text |
| `strasse`, `plz`, `ort`, `land` | Text |
| `telefon`, `handy`, `email` | Text |
| `anrede` | entfallen (07 §1): eine Spalte `anrede` in alten Dateien wird ignoriert, ohne Hinweis |
| `aktiv` | `1`/`0` |
| `abteilungen` | BLSV-Nummern mit Komma, z. B. `09,34` |
| `beitragsart` | Name der Beitragsart oder leer |
| `zahlart` | `lastschrift`/`selbst` |
| `iban`, `bic`, `kontoinhaber` | Text |
| `mandatsreferenz`, `mandatsdatum` | Text, Datum (leer = Default, 07 §1) |
| `familie` | Schlüssel der Familie (gleicher Wert = gleiche Familie) |
| `familie_name` | Anzeigename der Familie |
| `familie_rolle` | `zahler`/`elternteil`/`kind` |
| `funktionen` | Funktionsnamen mit Komma |
| `lizenznummer` | Text |
| `bemerkung` | Text, mehrzeilig in Anführungszeichen |

- Mehr als 20.000 Zeilen (`ImportLeser::MAX_ZEILEN`) gelten als falsche Datei
  und werden vor dem Lesen abgewiesen – sonst legte eine riesige Datei bei
  jedem Aufruf der Einstellungen den Speicher lahm (#37).

## 3. Umwandlung der Altdaten

Die Umwandlung der Exporte der alten Software in `import.csv` hat Mirko
einmalig auf seinem Rechner erledigt; der Erstimport ist gelaufen
(29.10.2026). Im Repository gibt es dafür **kein Skript** mehr (die
früheren Fassungen `tools/altdaten-umwandeln.*` stehen in der
Git-Historie). Die Zuordnungsregeln (Spalten, Beitragskombinationen,
Familienrollen) stehen in `docs/fachlichkeit.md` §8. Eine Datei für einen
späteren Abgleich (§4) wird ebenso lokal im Importformat (§2) erstellt –
UTF-8, `land` als Name, ISO-Code oder Kfz-Kennzeichen (`Deutschland`, `DE` und `D` sind gleich).

## 4. Abgleich mit einer späteren Datei

Gleiche Datei wie beim Erstimport (`import.csv` im Importformat, §3),
hochgeladen unter Einstellungen → Import, wenn der
Bestand nicht leer ist. Zugeordnet wird über die **Mitgliedsnummer**.

**Merge-Ansicht** – eine Seite, drei Abschnitte, je Zeile ein Häkchen:

| Abschnitt | Wer | Häkchen bedeutet | Vorauswahl |
|---|---|---|---|
| **Neu** | in der Datei, nicht in der Anwendung | Mitglied anlegen | an |
| **Fehlt in der Datei** | in der Anwendung (Herkunft `import`), nicht in der Datei: noch nicht ausgetreten, oder ehemalig ohne Jahresbeiträge | noch nicht ausgetreten: austreten lassen (Austrittsdatum oben auf der Seite, Default heute); ehemalig: „ganz löschen" | aus |
| **Geändert** | in beiden, Werte unterscheiden sich | Werte aus der Datei übernehmen | aus |

- „Geändert" zeigt je Mitglied die abweichenden Felder als
  „in der Anwendung → in der Datei". Übernommen wird je Mitglied ganz
  oder gar nicht – einzelne Felder danach von Hand ändern.
- Mitglieder, die in der Anwendung angelegt wurden (Herkunft `manuell`),
  erscheinen nie unter „Fehlt in der Datei".
- Gleiche Regel wie am Mitglied (07 §1, #127): **Austritt statt Löschen.**
  Ein noch nicht ausgetretenes Mitglied kann hier nur austreten; „ganz
  löschen" gibt es nur für Ehemalige (Austrittsdatum erreicht) ohne
  Jahresbeiträge. Ehemalige mit Jahresbeiträgen und Mitglieder mit
  vermerktem künftigem Austritt werden nicht angezeigt (hier gibt es für
  sie nichts zu tun). Wer im Abgleich austritt, lässt sich also frühestens
  beim nächsten Abgleich (oder am Mitglied) löschen.
- Familien: neue `FamID` ⇒ neue Familie; Wechsel der Familie oder Rolle
  erscheint als Änderung unter „Geändert".
- **Übernehmen** als Schrittkette; jede Änderung erzeugt eine Version
  mit Anlass `abgleich` (07 §7), sodass jeder Abgleich nachvollziehbar
  und je Mitglied zurückholbar ist. Danach eine Zusammenfassung (x neu,
  y ausgetreten, z geändert) und ein Audit-Eintrag.
- Alle Häkchen-Abschnitte haben „alle an/aus".

**Umsetzung Abgleich (#34):** derselbe Abschnitt „Import“ und dieselbe
Upload-Route `/admin/import/hochladen` – bei leerem Bestand Erstimport,
sonst Abgleich (Dienst `App\Service\Import\Abgleich`, Routen
`/admin/import/abgleich/*` in `app/routes/import.php`, Seite
`app/views/admin/einstellungen/import-abgleich.php`, Skript
`public/js/abgleich.js`).
- Datei wie beim Erstimport verschlüsselt als Blob (`ImportDateiAblage`,
  von beiden genutzt); Job-Typ `abgleich`, `phase` = `vorschau` |
  `abgleich` | `fertig`. Ein neuer Upload verwirft frühere Abgleiche samt
  Datei; „Bestand leeren“ ebenso. Eine Datei mit Fehlern zeigt nur die
  Fehler, abgeglichen wird nur eine fehlerfreie.
- Einteilung (`AbgleichVergleich`, ohne Datenbank): verglichen wird der
  ganze Stand von `Mitglied::daten()` mit dem Stand, den die Zeile
  verlangt (IBAN/BIC normalisiert wie gespeichert). Eine Spalte, die der
  Datei ganz fehlt, sagt nichts: der Wert in der Anwendung bleibt (und ist
  keine Änderung). Eine leere BIC wird aus der Bank einer gültigen
  deutschen IBAN ergänzt (#127, `Banken::bicErgaenzen()`) – auch wenn der
  Datei die Spalte `bic` fehlt; so erscheint eine fehlende BIC beim
  Abgleich als Änderung und wird mit übernommen. Der Erstimport ergänzt sie
  ebenso (`ImportLeser`). Familie: Zeile ohne
  `familie` = keine Familie; vorhandener Schlüssel = diese Familie;
  unbekannter Schlüssel = neue Familie (Name aus `familie_name`). Den
  Familiennamen selbst gleicht der Abgleich nicht ab. „Ehemalig“ heißt:
  Austrittsdatum erreicht (`Mitglied::istEhemalig()`, Stichtag heute). Ein von
  Hand angelegtes Mitglied, das in der Datei steht, kann unter „Geändert“
  erscheinen; es behält beim Übernehmen seine Herkunft.
- Häkchen: je Zeile „Anlegen“ / „Austreten“ oder „ganz löschen“ /
  „Übernehmen“; bei „Fehlt in der Datei“ hat ein noch nicht ausgetretenes
  Mitglied nur „Austreten“ (Spalte löschen: „erst nach dem Austritt“), ein
  ehemaliges nur „ganz löschen“ („ausgetreten am …“). „Alle an“ hakt dort
  nur „Austreten“ an, gelöscht wird immer Zeile für Zeile. Das Skript schickt je
  Abschnitt eine kommagetrennte Liste von Mitgliedsnummern (tausende
  Einzelfelder liefen in `max_input_vars`). Der Server teilt beim Start
  neu ein und nimmt nur Nummern, die noch in ihrem Abschnitt stehen;
  „ganz löschen“ eines nicht ehemaligen Mitglieds (auch per
  manipuliertem POST) lehnt den Start ab, „Austreten“ eines Ehemaligen
  wird ignoriert.
  Das Austrittsdatum ist nur nötig, wenn jemand austreten soll.
- Schrittkette: die Auswahl steht als Liste von (Art, Nummer) im Job –
  bei geändert, austreten und löschen als (Art, Nummer, ID) des beim Start
  geprüften Mitglieds –, 100 je Schritt in einer Transaktion, Reihenfolge
  neu → geändert → austreten → löschen. Geändert, austreten und löschen
  treffen nur dieses Mitglied (über die ID gefunden): trägt es inzwischen
  eine andere Nummer, wird die Zeile übersprungen – die Nummer kann
  zwischendurch jemand anderem gegeben worden sein. Ein vor diesem Update
  begonnener Abgleich hat keine IDs; fortgesetzt überspringt er seine
  übrigen Zeilen zu geändert/austreten/löschen, dafür die Datei noch
  einmal hochladen. Jede Art ist wiederholbar (vorhandene Nummer wird
  nicht doppelt angelegt, Speichern ohne Änderung schreibt keine Version,
  gelöscht ist gelöscht). Vor dem Löschen prüft der Schritt auf der
  gesperrten Zeile (`MitgliedRepository::mitIdsZumAendern()`) noch einmal:
  ist das Mitglied nicht mehr ehemalig (Austritt zurückgenommen) oder hat
  es zwischendurch einen Jahresbeitrag bekommen, bleibt es und die Zeile
  zählt als übersprungen. Neue
  Mitglieder tragen Herkunft `import`. Ein angefangener Abgleich lässt sich
  fortsetzen oder abbrechen; abgebrochen bleibt, was schon übernommen ist
  (Audit mit `abgebrochen`).
- Danach: Zusammenfassung als Meldung („x neu, y ausgetreten, z geändert“,
  ggf. gelöscht/übersprungen), Audit `abgleich.durchgefuehrt` mit den
  Zahlen, je gelöschtem Mitglied zusätzlich `mitglied.geloescht` (in
  derselben Transaktion wie das Löschen); die Datei
  wird gelöscht, die Job-Zeile behält nur die Zähler („Letzter Abgleich“).

## Pflicht-Tests

Importformat: gültige Datei, jede Fehlerart (fehlende Nummer, doppelte
Nummer, unbekannte Abteilung/Beitragsart, kaputtes Datum) wird in der
Vorschau gemeldet und nichts importiert; Import nur bei leerem Bestand;
Schrittkette idempotent bei Wiederholung eines Schritts; Familien und
Rollen korrekt verknüpft; Version 1 je Mitglied. Abgleich: richtige
Einteilung in Neu/Fehlt/Geändert/unverändert, manuell angelegte und
Mitglieder mit gesetztem Austrittsdatum nie zum Austreten unter „Fehlt",
nur angehakte Zeilen werden umgesetzt, „ganz löschen" eines noch nicht
ausgetretenen Mitglieds (auch per manipuliertem POST) abgelehnt, eines
Ehemaligen ohne Jahresbeiträge ausgeführt, Ehemalige mit Jahresbeiträgen
nie unter „Fehlt", Version mit Anlass `abgleich`, ein zweiter Abgleich mit
derselben Datei findet nichts mehr außer den eben Ausgetretenen zum
Löschen. Eine **erfundene** `import.csv` unter
`tests/Fixtures/import/beispiel.csv` (Fantasiedaten) importiert
fehlerfrei.
