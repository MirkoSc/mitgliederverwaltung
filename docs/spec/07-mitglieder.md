# 07 – Mitglieder

Quelle: `docs/fachlichkeit.md` §1–4, §6b, §9. Leitlinien: **einfach statt
perfekt**, **Hinweise statt Sperren** (CLAUDE.md).

## 1. Mitglied

Felder (alle optional außer Mitgliedsnummer und Nachname – auch hier
gilt: fehlt etwas, gibt es einen Hinweis, keine Sperre):

| Gruppe | Felder |
|---|---|
| Person | Mitgliedsnummer, Anrede, Titel, Vorname, Nachname, Namenszusatz, Geschlecht (`m`/`w`/`d`), Geburtsdatum |
| Anschrift | Straße, PLZ, Ort, Land (Default `D`) |
| Kontakt | Telefon, Handy, E-Mail |
| Mitgliedschaft | Eintrittsdatum (oder „unbekannt"), Austrittsdatum, Austrittsgrund (Freitext, Auswahlvorschläge „Austritt", „verstorben"), aktiv/passiv (Häkchen), Abteilungen (0..n) |
| Beitrag | Beitragsart (oder keine), Zahlart (`lastschrift`/`selbst`), IBAN, BIC (optional), Kontoinhaber falls abweichend, Mandatsreferenz, Mandatsdatum |
| Familie | Familie (oder keine), Rolle (`zahler`/`elternteil`/`kind`) |
| Funktionen | Häkchen je Funktion (§4), Übungsleiter-Lizenznummer |
| Sonstiges | Bemerkung (Freitext, mehrzeilig) |

- **Mitgliedsnummer**: ganze Zahl, eindeutig; neu = höchste + 1,
  änderbar. Importierte Mitglieder behalten ihre alte `Adressnummer`.
- **Ehemalige**: Austrittsdatum gesetzt und erreicht (berechnet, nicht
  gespeichert). In allen Listen standardmäßig ausgeblendet (Filter
  „Ehemalige anzeigen"). Löschen nur durch den Admin, mit Rückfrage:
  gelöscht werden Mitglied, Versionen, Nachweise und Jahresbeiträge;
  bereits erzeugte Einzüge behalten ihre eigene Kopie (08 §4).
- **Mandatsreferenz**: Default = Mitgliedsnummer (Muster folgt E6 aus der
  Sammlung, vor dem ersten echten Einzug). **Mandatsdatum**: Default =
  Eintrittsdatum, sonst das Default-Mandatsdatum aus den Einstellungen.
- IBAN wird beim Speichern normalisiert (Großbuchstaben, ohne
  Leerzeichen) und per Prüfziffer geprüft – falsch ⇒ Hinweis, gespeichert
  wird trotzdem.

Umsetzung des Formulars (#19): Reiter **Daten** (`App\App\Mitglied\
DatenReiter`, erster Reiter) zeigt alle Felder als ein Formular
(`MitgliedFormular`, Template `mitglied/formular`); „Neues Mitglied" ist
dasselbe Formular unter `GET /app/mitglieder/neu`. Gespeichert wird per
`POST /app/mitglieder/neu` bzw. `POST /app/mitglieder/{id}`
(`App\App\MitgliedController`, Routen `app/routes/mitglied.php`), danach
Weiterleitung auf `/app/mitglieder?mitglied={id}` mit dem Ergebnis als
Meldung. Blockiert wird nur bei fehlender/unbrauchbarer Nummer, fehlendem
Nachnamen, vergebener Nummer, einem unlesbaren Datum (`TT.MM.JJJJ` und
`JJJJ-MM-TT` werden gelesen) und einer Versionskollision (das Formular
kommt mit den eigenen Eingaben und der aktuellen Version zurück; erneutes
Speichern gilt). Familie und Rolle sind keine Felder dieses Formulars, sie
bleiben beim Speichern erhalten (§3). Abteilungen, Funktionen und
Beitragsarten bietet das Formular an, wenn sie aktiv sind oder das Mitglied
sie schon hat. Löschen (Admin): Rückfrage unter
`GET /app/mitglieder/{id}/loeschen`, dann `POST` derselben Adresse; die
Dateien der Nachweise werden mitgelöscht, das Audit-Log vermerkt
`mitglied.geloescht` nur mit ID und Mitgliedsnummer.

## 2. Abteilungen

Einstellungen: Name, **BLSV-Nummer** (Spartenkennzeichen, Text, z. B.
`09`), Sortierung, aktiv. Startbestand (Migration-Seed): Badminton `01`,
Fußball `09`, Kegeln `16`, Gymnastik `34`. Löschen nur ohne zugeordnete
Mitglieder, sonst deaktivieren.

## 3. Familien

- Familie = Name (z. B. „Familie Muster") + Mitglieder mit Rolle.
- Gepflegt im Mitglied: „Familie wählen" (Suche) oder „neue Familie",
  Rolle wählen. Die Familie zeigt im Mitglied ihre übrigen Personen.
- Eine Familie ohne Personen wird automatisch gelöscht.
- Umsetzung (#20): Reiter **Familie** am Mitglied, direkt nach „Daten"
  (`App\App\Mitglied\FamilieReiter`, Template `mitglied/familie`) zeigt
  Familie, Rolle und die übrigen Personen (mit Link). „Familie wählen oder
  anlegen" führt zu `GET /app/mitglieder/{id}/familie`; gesucht wird per
  `POST` auf dieselbe Adresse, der Name steht nie in der URL (#37) (Suche nach Name,
  Beitreten mit Rolle, „Neue Familie" mit Vorschlag „Familie <Nachname>");
  dazu `POST …/familie/{beitreten,neu,rolle,umbenennen,verlassen}`
  (`App\App\FamilieController`, `app/routes/familie.php`, Recht
  `mitglieder.bearbeiten`, also beide Rollen). Jede Änderung ist ein
  normales Speichern des Mitglieds (neue Version, Anlass `geaendert`); die
  Rolle ohne Angabe ist `elternteil`. Das Formular „Daten" enthält Familie
  und Rolle nicht, sie bleiben beim Speichern erhalten.
- Beitrag: der **Zahler** hat die Beitragsart „Familie"; Elternteile und
  Kinder haben normalerweise **keine** eigene Beitragsart (08 §1).

## 4. Funktionen

Einstellungen: Liste der Funktionen (Name, Sortierung, aktiv, „mit
Lizenznummer" ja/nein). Startbestand: Übungsleiter (mit Lizenznummer),
Wirtschaftshelfer, Bahndienst, Jugendtrainer. Am Mitglied als Häkchen.

## 5. Nachweise

Je Mitglied 0..n Nachweise: Art (`rente`/`studium`/`ausbildung`/
`sonstiges`), gültig bis (optional), Bemerkung, optional **eine Datei**
(Bild oder PDF, verschlüsselter Blob, 03 §3), **vermerkt von** (Benutzer)
und **am** – automatisch gesetzt, nicht änderbar. Nachweise werden nicht
versioniert (sie sind selbst ein Verlauf); löschen nur Admin.

Die Datei (PDF, JPEG, PNG, WebP, höchstens 10 MB; der Typ wird am Inhalt
erkannt) kommt mit dem Formular (multipart), nicht als Chunk-Upload: ein Scan
ist klein, das Formular geht ohne JavaScript. Der Download ist ein gestreamter
Anhang (`Cache-Control: no-store`). Gelöscht wird die Datei mit dem Nachweis
und mit dem Mitglied. Angezeigt im Reiter **Nachweise** direkt nach
„Beiträge" (`App\App\Mitglied\NachweiseReiter`, Template `mitglied/nachweise`).

## 6. Hinweise

Hinweise sind **berechnet**, nie gespeichert: eine reine Funktion
`App\Service\Mitglied\Hinweise::fuer(Mitglied, Kontext): list<Hinweis>`
(Kontext = Stichtag, Familie, Nachweise, Beitragsarten). Sie erscheinen
oben im Mitglied, nach dem Speichern und gesammelt in der Liste
„Auffälligkeiten" (09). Sie blockieren nie etwas.

| Hinweis | Bedingung |
|---|---|
| Rentner unter 67 | Beitragsart mit Regel `rentner`, Alter < 67 |
| Rentennachweis fehlt | Regel `rentner`, kein gültiger Nachweis `rente` |
| Jugendbeitrag ab 18 ohne Nachweis | Regel `jugend`, Alter ≥ 18, kein gültiger Nachweis `studium`/`ausbildung` |
| Nachweis abgelaufen | ein Nachweis mit „gültig bis" < heute |
| Aktiv/passiv passt nicht zur Beitragsart | Regel `aktiv` und Häkchen passiv bzw. Regel `passiv` und Häkchen aktiv |
| Familie zu klein | Familie mit < 2 Personen |
| Familie ohne bzw. mit mehreren Zahlern | Anzahl Rolle `zahler` ≠ 1 |
| Zahler ohne Familienbeitrag | Rolle `zahler`, Beitragsart ohne Regel `familie` |
| Familienmitglied mit eigenem Beitrag | Rolle `elternteil`/`kind` und eine Beitragsart gesetzt |
| Andere Anschrift als die Familie | Straße/PLZ weicht vom Zahler ab |
| Kind ist 18 | Rolle `kind`, Alter ≥ 18 |
| Kein Beitrag | weder Beitragsart noch Familie, nicht ehemalig |
| Lastschrift ohne gültige IBAN | Zahlart `lastschrift`, IBAN fehlt oder Prüfziffer falsch |
| Beitragsfrei ohne Grund | Regel `beitragsfrei`, Bemerkung leer |
| Eintrittsdatum unbekannt | Eintrittsdatum fehlt |
| Pflichtangaben für BLSV fehlen | Geschlecht oder Geburtsdatum fehlt |

Umsetzung (#21): `App\Service\Mitglied\Hinweise::fuer(Mitglied,
HinweisKontext): list<Hinweis>` liest nichts und speichert nichts; der
`HinweisKontext` trägt Stichtag, die Personen der Familie (das übergebene
Mitglied ersetzt das gespeicherte, so zählen auch ungespeicherte Änderungen),
die Nachweise und alle Beitragsarten. `HinweisLader` holt den Kontext aus den
Repositories. Jede Zeile der Tabelle ist eine `HinweisArt` (Reihenfolge der
Tabelle = Reihenfolge der Hinweise; die Liste „Auffälligkeiten" filtert
danach). Anzeige: `HinweiseAbschnitt` (gefundener Abschnitt am Mitglied, immer
der aktuelle Stand); nach dem Speichern sagt die Meldung nur, *dass* es
Hinweise gibt – sie stehen dann oben im Mitglied. Auslegung im Einzelnen:

- „Aktiv/passiv" vergleicht das Häkchen mit `aktiv_art` der Beitragsart
  (`aktiv`/`passiv`; `egal` passt zu beidem).
- Alter und „gültig bis" zählen tageweise: genau 18 bzw. 67 zählt als 18
  bzw. 67, ein Nachweis „gültig bis heute" ist heute noch gültig.
- „Nachweis abgelaufen": ein Hinweis je abgelaufenem Nachweis, auch wenn ein
  anderer gültig ist.
- „Andere Anschrift" vergleicht Straße und PLZ (ohne Groß-/Kleinschreibung
  und Leerzeichen am Rand) mit dem ersten Zahler der Familie; für den Zahler
  selbst und in Familien ohne Zahler entfällt er. Am Mitglied steht dazu die
  Anschrift des Zahlers; „Anschrift des Zahlers übernehmen" trägt Straße,
  PLZ, Ort und Land ins Formular „Daten" ein (`public/js/anschrift.js`),
  gespeichert wird erst mit dem Formular. Auf einem anderen Reiter führt ein
  Link zu „Daten".
- „Lastschrift ohne gültige IBAN" gilt nicht für Elternteil/Kind *ohne* eigene
  Beitragsart und *ohne* IBAN (sie zahlen über den Zahler); eine falsche IBAN
  wird immer gemeldet.
- Ehemalige: nur „Kein Beitrag" nimmt sie aus; die übrigen Regeln gelten
  unverändert.

## 7. Versionen

- Jedes Speichern eines Mitglieds, bei dem sich etwas ändert, erzeugt eine
  **Version**: vollständiger Stand aller Felder aus §1 (einschließlich
  Abteilungen, Funktionen, Beitragsart, Familie und Rolle), dazu wer, wann
  und ein Anlass (`angelegt`, `geaendert`, `importiert`, `abgleich`,
  `wiederhergestellt aus Version n`).
- Reiter **Verlauf**: Versionen absteigend; je Version die geänderten
  Felder als „alt → neu".
- **Zurückholen**: übernimmt die Werte der gewählten Version als **neue**
  Version hinten. Gibt es die Familie nicht mehr, bleibt die Familie leer
  (Hinweis); dasselbe für gelöschte Abteilungen/Funktionen.
- Umsetzung (#22): `VerlaufReiter` (Reiter `verlauf`, Template
  `mitglied/verlauf`) zeigt die Versionen mit „alt → neu" je geändertem
  Feld (`VerlaufAenderungen`, Namen aus den Versionen selbst; die erste
  Version zeigt keine Änderungen). „Zurückholen" ist
  `POST /app/mitglieder/{id}/verlauf/{nr}/zurueckholen`
  (`mitglieder.bearbeiten`, `VerlaufController`), ruft
  `MitgliedRepository::wiederherstellen()`, schreibt einen Audit-Eintrag
  (nur ID/Nummern) und zeigt die Hinweise als Meldung.

- Nicht versioniert: Jahresbeiträge/Einzüge (08), Nachweise (§5),
  Ehrungs-Vermerke (09).

## 8. Datenmodell

Normale Spalten im Klartext (E-11, 03); Suche, Filter und Sortierung in
SQL.

| Tabelle | Spalten |
|---|---|
| `mitglied` | id, mitgliedsnummer UNIQUE, anrede, titel, vorname, nachname, namenszusatz, geschlecht, geburtsdatum, strasse, plz, ort, land, telefon, handy, email, eintrittsdatum NULL, austrittsdatum NULL, austrittsgrund, aktiv, beitragsart_id NULL, zahlart, iban, bic, kontoinhaber, mandatsreferenz, mandatsdatum NULL, familie_id NULL, familie_rolle NULL, lizenznummer, bemerkung, herkunft (`import`/`manuell`), version, created_at, updated_at |
| `mitglied_abteilung` | mitglied_id, abteilung_id |
| `mitglied_funktion` | mitglied_id, funktion_id |
| `mitglied_version` | id, mitglied_id, nr, anlass, geaendert_von (user_id), geaendert_am, daten (JSON: vollständiger Stand inkl. Abteilungen, Funktionen, Familie) |
| `familie` | id, name, import_schluessel NULL (FamID der alten Software, 10 §4), created_at |
| `nachweis` | id, mitglied_id, art, gueltig_bis NULL, bemerkung, blob_id NULL (verschlüsselte Datei, 03 §3), vermerkt_von, vermerkt_am |
| `abteilung` | id, name, blsv_nummer, sortierung, aktiv |
| `funktion` | id, name, mit_lizenz, sortierung, aktiv |

- „Ehemalig" ist berechnet: `austrittsdatum <= heute`.
- **Suche** (#88): ein Suchfeld, live über htmx, in SQL (`LIKE`, Prepared
  Statements, kein Volltextindex). Die Eingabe wird in Wörter zerlegt
  (Leerzeichen, Komma); **jedes Wort muss in irgendeinem Feld passen**,
  die Reihenfolge ist egal („Hauptstr 12 Musterort", „Müller 1980").
  Groß-/Kleinschreibung egal; ein Punkt am Wortende entfällt
  („Hauptstr." findet „Hauptstraße"). Felder: Nachname, Vorname,
  Mitgliedsnummer, Straße, PLZ, Ort, E-Mail, Telefon, Handy,
  Kontoinhaber, Bemerkung sowie
  - **IBAN**: ohne Leerzeichen und ohne Groß-/Kleinschreibung verglichen,
    ein Teilstück genügt; gilt für Wörter aus mindestens vier Buchstaben/
    Ziffern (kürzere Stücke träfen fast jede IBAN),
  - **Geburtsdatum**: ein Wort, das ein gültiges Datum ist (`1.2.1980`,
    `01.02.1980`, `1980-02-01`, `1.2.80`), vergleicht exakt; zwei Ziffern
    für das Jahr zählen zum laufenden Jahrhundert, außer das läge in der
    Zukunft. Eine vierstellige Zahl passt zusätzlich auf das Geburtsjahr.
  Die Suche ist mit allen Filtern kombinierbar.
- **Filter Beitragsart**: „X" findet die Mitglieder mit der Beitragsart X
  **und** die Mitglieder der Familien, deren Zahler (Rolle `zahler`) X hat
  (sie zahlen ja darüber, §3). Wer nur über die Familie gefunden wird,
  trägt in der Trefferliste den Vermerk „über Familie <Name>"
  (`MitgliedZeile::$ueberFamilie`); wer selbst X hat, trägt keinen.

Umsetzung der Mitglieder-Seite (#18): `GET/POST /app/mitglieder`
(`App\App\MitgliederController`, Liste in `MitgliederListeRepository`,
Filter `MitgliederFilter`). Der Suchtext reist nur im POST-Body, nie in der
URL (CLAUDE.md §4); Filter, Sortierung, Seite und das geöffnete Mitglied
stehen in der URL. Ehemalige sind ohne Haken „Ehemalige anzeigen"
ausgeblendet. Das geöffnete Mitglied nimmt Reiter und Abschnitte aus den
Erweiterungspunkten `App\App\Mitglied\*` (#19 ff.).

Umsetzung (#15, gemeinsame API für alle späteren Issues):

- Domain-Typen in `App\Domain` (`Mitglied`, `Familie`, `Abteilung`,
  `Funktion`, `Beitragsart`, `Nachweis`, `MitgliedVersion`,
  `VersionsAnlass` …), unveränderlich; geänderte Kopie mit
  `$wert->mit([...])`. Repositories in `App\Repository`.
- Leere Texte sind `''` (Spalte `NOT NULL DEFAULT ''`), fehlende Daten und
  Auswahlen `NULL`.
- `MitgliedRepository::speichern()` schreibt Zeile, Zuordnungen und
  Version in **einer** Transaktion. Verglichen wird
  `Mitglied::daten()` (alle Felder aus §1); gleich ⇒ keine Version, kein
  `updated_at`. `mitglied.version` = Nummer der letzten Version und
  zugleich Sperre gegen gleichzeitiges Bearbeiten: ein Speichern auf Basis
  einer älteren Version wird abgelehnt (`VersionKonfliktException`), statt
  fremde Änderungen still zu überschreiben.
- `mitglied_version.daten` = `Mitglied::daten()` plus `bezeichnungen`
  (Namen von Familie, Beitragsart, Abteilungen, Funktionen zum Zeitpunkt
  der Version), damit der Verlauf nach Umbenennen/Löschen lesbar bleibt.
  Verglichen und zurückgeholt werden nur die IDs.
- `herkunft` ist kein Feld aus §1 und wird nicht versioniert.
- Zurückholen (`wiederherstellen()`): nicht mehr vorhandene Familie,
  Beitragsart, Abteilungen, Funktionen bleiben leer; ist die alte
  Mitgliedsnummer inzwischen vergeben, bleibt die aktuelle. Jeder Fall
  kommt als Hinweistext zurück. Entspricht die Version dem aktuellen Stand,
  entsteht wie bei jedem Speichern ohne Änderung keine neue Version.
- Mandatsreferenz/-datum werden gespeichert wie eingegeben; der Default
  (§1) wird bei Bedarf berechnet (`effektiveMandatsreferenz()`,
  `effektivesMandatsdatum()`), nicht eingetragen.
- IBAN-Prüfung `App\Support\Iban`, Alter `App\Support\Alter`
  (29.02. zählt in Nicht-Schaltjahren am 28.02., wie in 09 §2).

## 9. Rechte

| Aktion | Admin | Vorstand |
|---|:-:|:-:|
| Mitglieder suchen, ansehen, anlegen, bearbeiten, Version zurückholen | ✓ | ✓ |
| Nachweis vermerken | ✓ | ✓ |
| Nachweis löschen, Mitglied löschen | ✓ | – |
| Familien pflegen | ✓ | ✓ |
| Reiter „Beiträge" | ✓ | – |

## Pflicht-Tests

Hinweis-Funktion: jede Zeile der Tabelle §6 positiv und negativ, Grenzfälle
(genau 18, genau 67, Nachweis gültig bis heute). Version: Speichern ohne
Änderung erzeugt keine Version; Zurückholen hängt eine neue Version an und
lässt alte unverändert; Zurückholen bei gelöschter Familie. Mitgliedsnummer
eindeutig, nächste freie Nummer. IBAN-Prüfziffer. Suche findet über alle
Suchfelder, Groß-/Kleinschreibung egal; Geburtsdatum in mehreren
Schreibweisen, IBAN mit/ohne Leerzeichen und als Teilstück, Adresse mit Name,
Bemerkung, Wort ohne Treffer; Filter Beitragsart findet Zahler und
Familienmitglieder. Rechte-Matrix §9 serverseitig.
