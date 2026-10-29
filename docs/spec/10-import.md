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
2. Das Umwandlungsskript (§3) erzeugt daraus `import.csv` – auf Mirkos
   Rechner, die Dateien verlassen ihn nicht.
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

## 2. Importformat `import.csv`

UTF-8, Trennzeichen `;`, erste Zeile Spaltennamen, Datum `TT.MM.JJJJ`,
leere Zelle = kein Wert. Eine Zeile je Mitglied.

| Spalte | Inhalt |
|---|---|
| `mitgliedsnummer` | Pflicht, ganze Zahl |
| `anrede`, `titel`, `vorname`, `nachname`, `namenszusatz` | Text |
| `geschlecht` | `m`/`w`/`d` |
| `geburtsdatum`, `eintrittsdatum`, `austrittsdatum` | Datum; Eintritt leer = unbekannt |
| `austrittsgrund` | Text |
| `strasse`, `plz`, `ort`, `land` | Text |
| `telefon`, `handy`, `email` | Text |
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

## 3. Umwandlungsskript (alte Software → `import.csv`)

`tools/altdaten-umwandeln.php` – **nicht** im Release (CLAUDE.md §2),
läuft lokal per Docker:

```bash
docker compose run --rm app php tools/altdaten-umwandeln.php import-daten/mitglieder.xlsx import-daten/familien.xlsx > import-daten/import.csv
```

- `import-daten/` steht in `.gitignore` – echte Daten kommen nie ins Repo.
- Liest xlsx mit einem kleinen eigenen Leser (nur Text und Zahlen des
  ersten Blatts).
- Spaltenzuordnung und Beitrags-Zuordnung genau nach
  `docs/fachlichkeit.md` §8 (Tabellen „Zuordnung (Vorschlag)" und
  „Zuordnung alt → neu"):
  - Mitgliedsnummer = `Adressnummer`; Zeilen ohne Mitgliedschaft (keine
    aktuelle Abteilung) werden nicht übernommen, ihr Name dient als
    `kontoinhaber` der Mitglieder, für die sie zahlen (`ZahlerAdressNummer`).
  - Beitragsart und `aktiv` aus der Kombination der aktuellen
    Beitragsnamen; bei Familien `aktiv` aus der Notiz.
  - Abteilung „Turnen" → BLSV-Nummer `34` (heißt künftig „Gymnastik").
  - Familien aus der Familiendatei; Rolle: „Familienkarte" → `zahler`,
    sonst unter 18 → `kind`, sonst `elternteil`.
  - Übungsleiter aus `Uel*` → Funktion „Übungsleiter" + Lizenznummer.
  - Platzhalterdatum `01.01.1901` → leer (unbekannt).
  - IBAN ohne Leerzeichen; Notizen (`AdrNotizen`, `MitNotizen`)
    zusammengefügt in `bemerkung`.
- Schreibt zusätzlich eine kurze Zusammenfassung nach STDERR (Anzahl je
  Beitragsart, Familien, nicht zuordenbare Zeilen) – ohne Namen.
- Optional `--stichtag=TT.MM.JJJJ`: Tag, an dem „unter 18" gemessen wird
  (Default heute); macht Läufe und Tests reproduzierbar.
- Aufbau: `tools/altdaten-umwandeln.php` (Einstieg) und
  `tools/altdaten/` (`XlsxLeser`, `Umwandler`, `Ergebnis`, `Zaehler`,
  `Kommandozeile`; Namespace `Tools\Altdaten`, nicht im Autoloader, nicht
  im Release).
- Spaltennamen der Altdatei wie in `docs/fachlichkeit.md` §8; verglichen
  wird ohne Groß-/Kleinschreibung und Satzzeichen. Pflicht sind
  `Adressnummer` und `Nachname` (sonst Abbruch mit Hinweis, „falsche
  Datei?"); fehlt eine andere Spalte, steht sie in der Zusammenfassung.
  Abteilungen, Beitragsnamen: Spalten „… (alle aktuell)(1..3)", die
  Historie „(alle)(1..3)" wird nie gelesen. Ein Platz „Abteilung + Beitrag"
  kann eine Abteilung mehrfach nennen (Grundbeitrag und Zuschlag „aktiv"),
  Abteilungen werden einmal gezählt.
- Mehrere Grundbeiträge bei einem Mitglied: der höchste zählt
  (Familienkarte, Erwachsene, Kinder, Jugendliche, Rentner,
  Mindestbeitrag, Beitragsfrei FK, Beitragsfrei); „aktiv" ist ein Zuschlag
  in irgendeinem Platz. Unbekannter Beitragsname oder gar kein
  Grundbeitrag: Mitglied wird übernommen, aber ohne Beitragsart, und
  erscheint in der Zusammenfassung.
- Abweichender Zahler: `ZahlerAdressNummer` ≠ eigene Nummer (oder ohne
  Nummer: Zahlername ≠ eigener Name). `kontoinhaber` = `ZahlerVorname
  ZahlerNachname`, sonst Name der Zeile mit dieser Adressnummer; `iban` =
  `MitZahlerIban`, sonst `Konto1Iban`, sonst IBAN der Zahlerzeile.
- `land` = `Lkz`, sonst `Land`. Datumswerte werden auf `TT.MM.JJJJ`
  gebracht (auch `JJJJ-MM-TT` und Excel-Seriennummern); Platzhalter
  `01.01.1901` und nicht lesbare Daten werden leer (Zähler in der
  Zusammenfassung). Zahlart „Lastschriftzahler" → `lastschrift`,
  „Rechnungszahler" → `selbst`, sonst leer.
- Übungsleiter: die Lizenz-Spalte unter den `Uel*`-Spalten (erste mit
  „Lizenz", sonst „Nummer"/„Nr" im Namen); Wert nicht leer und nicht `0` ⇒
  Funktion „Übungsleiter" + `lizenznummer`.

## 4. Abgleich mit einer späteren Datei

Gleiche Datei wie beim Erstimport (`import.csv` aus dem
Umwandlungsskript), hochgeladen unter Einstellungen → Import, wenn der
Bestand nicht leer ist. Zugeordnet wird über die **Mitgliedsnummer**.

**Merge-Ansicht** – eine Seite, drei Abschnitte, je Zeile ein Häkchen:

| Abschnitt | Wer | Häkchen bedeutet | Vorauswahl |
|---|---|---|---|
| **Neu** | in der Datei, nicht in der Anwendung | Mitglied anlegen | an |
| **Fehlt in der Datei** | in der Anwendung (Herkunft `import`), nicht in der Datei | austreten lassen: Austrittsdatum setzen (Datum oben auf der Seite, Default heute); wahlweise „ganz löschen" je Zeile | aus |
| **Geändert** | in beiden, Werte unterscheiden sich | Werte aus der Datei übernehmen | aus |

- „Geändert" zeigt je Mitglied die abweichenden Felder als
  „in der Anwendung → in der Datei". Übernommen wird je Mitglied ganz
  oder gar nicht – einzelne Felder danach von Hand ändern.
- Mitglieder, die in der Anwendung angelegt wurden (Herkunft `manuell`),
  erscheinen nie unter „Fehlt in der Datei".
- Bereits Ausgetretene, die in der Datei fehlen, werden nicht angezeigt.
- „Ganz löschen" ist nur möglich, wenn das Mitglied keine Jahresbeiträge
  hat; sonst nur austreten lassen.
- Familien: neue `FamID` ⇒ neue Familie; Wechsel der Familie oder Rolle
  erscheint als Änderung unter „Geändert".
- **Übernehmen** als Schrittkette; jede Änderung erzeugt eine Version
  mit Anlass `abgleich` (07 §7), sodass jeder Abgleich nachvollziehbar
  und je Mitglied zurückholbar ist. Danach eine Zusammenfassung (x neu,
  y ausgetreten, z geändert) und ein Audit-Eintrag.
- Alle Häkchen-Abschnitte haben „alle an/aus".

## Pflicht-Tests

Importformat: gültige Datei, jede Fehlerart (fehlende Nummer, doppelte
Nummer, unbekannte Abteilung/Beitragsart, kaputtes Datum) wird in der
Vorschau gemeldet und nichts importiert; Import nur bei leerem Bestand;
Schrittkette idempotent bei Wiederholung eines Schritts; Familien und
Rollen korrekt verknüpft; Version 1 je Mitglied. Abgleich: richtige
Einteilung in Neu/Fehlt/Geändert/unverändert, manuell angelegte und
ausgetretene Mitglieder nie unter „Fehlt", nur angehakte Zeilen werden
umgesetzt, „ganz löschen" bei vorhandenen Jahresbeiträgen abgelehnt,
Version mit Anlass `abgleich`, ein zweiter Abgleich mit derselben Datei
findet nichts mehr. Umwandlungsskript gegen
eine **erfundene** Beispiel-Altdatei unter `tests/Fixtures/altdaten/`
(gleiche Spaltennamen wie die echte, Fantasiedaten; `erzeugen.php` baut die
xlsx aus `daten.php`): alle 12
Beitragskombinationen, Familie, abweichender Zahler, Übungsleiter,
Platzhalterdatum.
