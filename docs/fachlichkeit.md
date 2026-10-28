# Fachlichkeit – Sammlung (Brainstorm)

Protokoll der fachlichen Klärung. **Verbindlich sind die Specs 07–10**
unter `docs/spec/`, die daraus entstanden sind; bei Widerspruch gelten
die Specs. Offene Punkte (E6, L3) werden hier geklärt und dann in die
Specs übernommen. Einstufung: **muss** · **soll** · **später** · **nicht**.
Offene Fragen stehen am Ende jedes Bereichs mit einem Vorschlag.

## 0. Leitlinien

- **Einfach statt perfekt.** Die Anwendung wird nicht verkauft und muss
  keinen Bestwert erreichen. Sie soll deutlich schlanker sein als
  Vereinsbelege: wenige Seiten, wenige Einstellungen, keine Sonderfälle auf
  Vorrat. Im Zweifel die einfachste Lösung, die funktioniert.
- **Hinweise statt Sperren.** Fachliche Regeln (Altersgrenzen, Nachweise,
  Familiengröße, Haushalt …) erzeugen **Hinweise**, blockieren aber nie.
  Alles, was technisch speicherbar ist, darf gespeichert werden, auch
  wenn es „eigentlich falsch" ist. Hinweise erscheinen beim Speichern und
  in einer Übersicht „Auffälligkeiten".
- **Nichts zaubern beim Import.** Es gibt ein festes, eigenes
  Importformat. Die Umwandlung der Altdaten in dieses Format passiert
  einmalig außerhalb der Anwendung.
- **Raus kommt man immer.** Alle Mitglieder jederzeit als Excel-Datei
  exportierbar – für einen späteren Umzug zu einer anderen Lösung.

## 1. Benutzer

- **muss** Nutzer sind der Vorstand bzw. ein kleiner Verwaltungskreis
  (Hauptnutzer: Mirko). Verein mit rund **700 Mitgliedern**.
- **muss** Zwei feste Rollen, kein Rollen-Editor:
  - **Admin** (Mirko): darf alles.
  - **Vorstand**: Mitglieder suchen und anlegen, aber **keine Finanzen**
    (Beiträge, Einzug, Bezahlt-Status).
- **nicht** Zugriff für Mitglieder selbst (E-F2: geklärt, vorerst nein).
- **nicht** Öffentliche Seiten außer Anmeldung, Impressum, Datenschutz
  (E-F3: geklärt, vorerst nein).
- **muss** „Keine Finanzen" heißt: Der Vorstand darf Mitglieder anlegen
  und bearbeiten (auch IBAN, Abteilung, Beitragsart). Einzug, Bezahlt-
  Status und Lastschriftdatei sind dem Admin vorbehalten (B2 ✅).

## 2. Mitglieder – Stammdaten

- **muss** Klassische Stammdaten: Name, Vorname, Anschrift, Telefon,
  E-Mail, Geburtsdatum, IBAN, Eintrittsdatum.
- **muss** Suche nach Mitgliedern.
- **muss** Aktiv/passiv ist ein eigenes Häkchen am Mitglied, unabhängig
  von der Beitragsart; passt beides nicht zusammen, gibt es einen Hinweis
  (BT4 ✅).
- **muss** Geschlecht (gebraucht für die BLSV-Meldung, §7).
- **muss** Mitgliedsnummer = `Adressnummer` der alten Software (X2 ✅); neue
  Mitglieder bekommen die nächste freie Nummer (M1 ✅).
- **muss** Austritt mit Datum und Grund (z. B. „verstorben"); Ehemalige
  bleiben gespeichert, standardmäßig ausgeblendet, Löschen nur von Hand
  (M2/M3 ✅).
- **muss** Optionales Feld „Kontoinhaber, falls abweichend" (M4 ✅).
- **muss** Felder am Mitglied (M5 ✅ – nichts darüber hinaus):

  | Gruppe | Felder |
  |---|---|
  | Person | Mitgliedsnummer, Anrede, Titel, Vorname, Nachname, Namenszusatz, Geschlecht, Geburtsdatum |
  | Anschrift | Straße, PLZ, Ort, Land |
  | Kontakt | Telefon, Handy, E-Mail |
  | Mitgliedschaft | Eintrittsdatum, Austrittsdatum + Grund, aktiv/passiv, Abteilungen (0..n) |
  | Beitrag | Beitragsart, Zahlart (Lastschrift / selbst zahlen), IBAN, BIC (optional), Kontoinhaber falls abweichend, Mandatsreferenz, Mandatsdatum |
  | Familie | Familie, Rolle (Zahler / Elternteil / Kind) |
  | Nachweise | Art, gültig bis, Vermerk von wem/wann, Datei optional |
  | Ehrungen | erhaltene Ehrungen (Stufe, Jahr) |
  | Funktionen | Häkchen je Funktion (z. B. Übungsleiter, Wirtschaftshelfer, Bahndienst, Jugendtrainer); Übungsleiter mit Lizenznummer |
  | Sonstiges | Bemerkung (Freitext) |

  Dazu je Jahr der Jahresbeitrag (Betrag, Status offen / im Einzug /
  bezahlt, Vermerke) – siehe §5.

## 3. Abteilungen

- **muss** Abteilungen frei einrichtbar (heute: Fußball, Gymnastik,
  Kegeln, Badminton), je mit **BLSV-Nummer** (Sportart/Fachverband).
- **muss** Zuordnung eines Mitglieds zu Abteilungen ist optional
  (z. B. sehr alte Mitglieder ohne bekannte Abteilung); ein Mitglied kann
  in **mehreren** Abteilungen sein (A1 ✅).
- **muss** Auch passive Mitglieder dürfen Abteilungen haben, müssen aber
  nicht (A2 ✅).
- **muss** Abteilung „Turnen" (34) heißt künftig „Gymnastik" (X5 ✅).

## 4. Familien

- **muss** Familie = Gruppe von Mitgliedern, eine Person ist
  **Zahler** (häufig der Vater), die anderen hängen daran und zahlen
  keinen eigenen Beitrag.
- **muss** Kinder sind bis 18 in der Familienkarte berechtigt.
- **muss** Jeder darf einer Familie zugeordnet werden; jede Person hat eine
  **Rolle**: Zahler, Elternteil oder Kind. Der 18-Jahre-Hinweis gilt nur
  für die Rolle „Kind" (F2 ✅).
- **muss** Regeln nur als Hinweis (Leitlinie „Hinweise statt Sperren"):
  - Familie hat weniger als **2** Personen (F1 ✅),
  - Mitglieder einer Familie wohnen nicht an derselben Anschrift,
  - ein Kind in der Familie ist 18 oder älter – nur Hinweis, Umstellung
    von Hand (F3 ✅).
- **muss** Die alte Software hat einen eigenen Familien-Export
  (`FamID`, `AdrNr`; F4 ✅) – siehe §8.

## 5. Beiträge

### Beitragsarten (einstellbar und veränderbar)

| Beitragsart | Betrag/Jahr | Regel (nur Hinweis) |
|---|---|---|
| Aktiv | 85 € | – |
| Passiv | 50 € | – |
| Jugend/Schüler/Studenten/Azubis | 45 € | bis 18; darüber nur mit Nachweis Studium/Ausbildung |
| Familie | 130 € | ab 2 Personen in einem Haushalt (§4) |
| Rentner aktiv | 50 € | ab 67 mit Rentennachweis |
| Rentner passiv | 30 € | ab 67 mit Rentennachweis |
| Mindestbeitrag | 25 € | für Helfer, Trainer, Funktionäre (BT6 ✅) |
| Beitragsfrei | 0 € | Grund als Pflicht-Bemerkung, z. B. Bildung und Teilhabe; nicht im Einzug (BT7 ✅) |

- **muss** Beitragsarten und Beträge im Admin pflegbar (auch später
  änderbar, z. B. der Mindestbeitrag); jede Beitragsart ist jedem
  Mitglied zuweisbar, unabhängig vom Alter (Hinweis, keine Sperre –
  z. B. 9-Jähriger als Rentner).
- **muss** Ein Beitrag **je Mitglied**, egal in wie vielen Abteilungen –
  die Beitragsstruktur wurde umgestellt, Abteilungsbeiträge gibt es nicht
  mehr (BT5 ✅).
- **muss** Beitrag ist jährlich, Stichtag 1.1.
- **muss** Ein geänderter Betrag gilt ab dem nächsten Beitragsjahr;
  bereits erzeugte Jahresbeiträge bleiben unverändert (BT3 ✅).
- **muss** Eintritt im laufenden Jahr: anteiliger Beitrag wird
  **vorgeschlagen** (nach angefangenen Monaten, Eintritt im Mai → 8/12)
  und ist von Hand änderbar (BT1 ✅). Bei ~700 Mitgliedern betrifft das
  nur wenige Fälle im Jahr.
- **muss** Nachweise (Rente, Studium, Ausbildung): Vermerk mit
  Ablaufdatum, **wer** ihn wann gemacht hat, optional eine Datei (Scan)
  dazu – die Datei ist keine Pflicht (BT2 ✅). Abgelaufene Nachweise
  erzeugen einen Hinweis.
- **nicht** Rabatt-Funktion: Auszubildende bekommen die Beitragsart
  „Jugend/…/Azubis", Einzelfälle über den von Hand änderbaren
  Jahresbeitrag (BT8 ✅).

### Einzug und Bezahlt-Status

- **muss** Auf Knopfdruck eine **einfache SEPA-Lastschriftdatei** für die
  Sparkasse Aschaffenburg Miltenberg erzeugen: DFÜ-Abkommen Anlage 3
  **Version 3.7** → SEPA-Basislastschrift **pain.008.001.08** (E2 ✅).
  Erste Datei als Test im Online-Banking hochladen.
- **muss** Haupteinzug einmal im Jahr zwischen Januar und März (meist
  Februar) über den **vollen Jahresbeitrag** – auch bei Einzug im März.
  Nur Neueintritte im laufenden Jahr zahlen anteilig, dafür einzelne
  Nachzügler-Einzüge. Ein Einzugslauf umfasst die offenen Beiträge mit
  IBAN, die der Admin auswählt (E5 ✅).
- **muss** Der Einzug wird je Mitglied gemerkt: Datei erzeugen → Status
  „im Einzug" → nach Bestätigung, dass eingezogen wurde → „bezahlt".
- **muss** Jedes Mitglied jederzeit manuell auf „bezahlt" setzbar
  (Barzahlung, Überweisung).
- **muss** Rücklastschrift: Mitglied von Hand zurück auf „offen", mit
  Vermerk (E3 ✅).
- **muss** Mitglieder ohne IBAN bzw. „Rechnungszahler" erscheinen in einer
  eigenen Liste „selbst kassieren" (E4 ✅).
- **muss** Der Verein hat eine Gläubiger-ID, die alte Software hat
  bereits Lastschriften erzeugt (E1 ✅). Gläubiger-ID, Bankverbindung und
  weitere Vereinsdaten (Anschrift, Kontakt, Register, Steuer,
  Verbandsnummern BLSV/BFV/Kegeln/…, Logo) pflegt der Admin in den
  Einstellungen – Spec 11.
- **nicht** SEPA-Mandate einholen und verwalten. Rücklastschriften klärt
  der Vorstand persönlich.
- Technisch verlangt die Datei je Mitglied eine Mandatsreferenz und ein
  Mandatsdatum sowie die Gläubiger-ID des Vereins. Einfach gelöst:
  Mandatsreferenz wie bisher (E6), Mandatsdatum = Eintrittsdatum bzw.
  einstellbares Datum, Gläubiger-ID als Einstellung.
- Vorlage für den Code: Vereinsbelege erzeugt schon pain.001-Dateien
  (Überweisungen); pain.008 folgt demselben Muster.

Offen:
- E6 (Mirko prüft; wird erst vor der ersten echten Lastschriftdatei
  gebraucht, blockiert den Start nicht): Der Export der alten Software enthält **keine
  Mandatsreferenz**. Welche Referenz stand in den bisherigen
  Lastschriften? Im Online-Banking bei einer Lastschrift nachsehen, wie
  die Mandatsreferenz aussieht (nur das Muster, z. B. „Mitgliedsnummer"
  oder „VEREIN-0123"). Bleibt die Referenz gleich, sieht die Bank keinen
  Unterschied.

## 6. Geburtstagskalender

- **muss** Übersicht, wer wann Geburtstag hat, mit Abteilung und Alter.
- **muss** Zeiträume: Woche, Monat, Jahr (als einfache Liste).
- **muss** Runde Geburtstage markiert; **welche Alter als rund gelten,
  ist einstellbar** (z. B. 18, 50, 60, 70, 75, 80 …). Filter „nur runde
  Geburtstage".
- **muss** Jede Ansicht exportierbar (xlsx oder csv).
- **muss** Ehemalige und Verstorbene ausgeblendet (G2 ✅).

## 6a. Ehrungen

- **muss** Ehrungsstufen einstellbar als Jahre der Mitgliedschaft
  (z. B. 25, 40, 50, 60, 70).
- **muss** Liste „wer wird dieses Jahr geehrt": Mitglieder, deren
  Eintrittsjahr + Stufe = gewähltes Jahr; mit Abteilung, exportierbar.
- Gezählt wird ab dem Eintrittsdatum, ohne Unterbrechungen
  (Austritt/Wiedereintritt) zu berücksichtigen.

- **muss** Ein Klick „geehrt" je Mitglied und Stufe vermerkt die Ehrung
  (Datum); die Liste zeigt „noch offen" (H1 ✅).
- **muss** Unbekanntes bzw. Platzhalter-Eintrittsdatum (alte Software
  teils „01.01.1901") wird als „unbekannt" übernommen; solche Mitglieder
  fehlen in der Ehrungsliste und erscheinen als Hinweis (H2 ✅).

## 6b. Funktionen im Verein

- **muss** Funktionen als **Häkchen** am Mitglied; die Liste der
  Funktionen ist einstellbar (Start: Übungsleiter, Wirtschaftshelfer,
  Bahndienst, Jugendtrainer – wie heute in den Notizen).
- **muss** Übungsleiter zusätzlich mit Lizenznummer.
- **muss** Filter/Liste je Funktion, exportierbar.
- Import: Übungsleiter samt Lizenznummer aus den `Uel*`-Spalten
  (15 Personen); die übrigen Funktionen stehen heute nur in den Notizen
  und werden nach dem Import von Hand angehakt.

## 7. BLSV-Meldung

- **muss** Jährliche Meldung aller Mitglieder an den BLSV als Export im
  Format der BLSV-Excel-Vorlage; Abteilungen mit ihrer BLSV-Nummer.
- Vorlage „BE2025 Mitgliederimport" (L1 ✅): ein Blatt, Spalten
  **Titel, Name, Vorname, Namenszusatz, Geschlecht, Geburtsdatum,
  Spartenkennzeichen**. Spartenkennzeichen = Nummer der Abteilung.

Offen:
- L3 (Mirko liefert, BLSV-Portal gerade in Wartung): genaue Formate der
  Felder (Geschlecht als m/w/d?, Datumsformat), eine Zeile je Mitglied
  oder je Mitglied und Sparte, Umgang mit Mitgliedern ohne Abteilung.
- L2: Stichtag und wer mitzählt, ergibt sich aus der BLSV-Vorlage
  (wird mit der Vorlage geklärt).
- Die alte Software führt die Abteilungen bereits mit zweistelligen
  Nummern (z. B. „09" Fußball, „34" Turnen) – vermutlich die
  BLSV-Sportartnummern. Mit der Vorlage abgleichen.

## 8. Export und Import

- **muss** Export aller Mitglieder als Excel-Datei, jederzeit, mit allen
  Feldern – Datenschutz ist hier bewusst nachrangig (die Datei ist danach
  unverschlüsselt; die Anwendung weist darauf hin).
- **muss** Erstimport aus der alten Mitgliederverwaltung (Excel/CSV):
  1. Mirko zeigt den Aufbau der Datei – **nur Kopfzeile plus zwei
     erfundene Beispielzeilen**, keine echten Mitgliederdaten (öffentliches
     Repo, Chat).
  2. Gemeinsam: Spalten zuordnen – was brauchen wir, was nicht.
  3. Daraus ein festes Importformat der Anwendung.
  4. Claude schreibt ein kleines Umwandlungsskript (läuft lokal bei
     Mirko, echte Daten verlassen den Rechner nicht), das die Altdatei in
     das Importformat bringt.
  5. Import in die Anwendung, mit Vorschau und Hinweisliste.
- **nicht** Ein Import, der beliebige Formate erkennt.

- **muss** Alle Exporte wahlweise als **xlsx oder csv** (X1 ✅).

### Altdatei: Export der bisherigen Software

Analysiert wurden zwei Exporte: der volle Aufbau (167 Spalten) an
wenigen Beispielen und ein vollständiger Export aller ~716 Mitglieder
ohne Namen/Anschriften (102 Spalten). Die Dateien und ihre Inhalte
bleiben außerhalb des Repos; hier stehen nur Spalten, Kategorien und
Zählungen.

- Ein Blatt, **167 Spalten**, eine Zeile je Mitglied. Datumswerte als
  Text `TT.MM.JJJJ`, IBAN mit Leerzeichen gruppiert, Telefonnummern in
  freien Schreibweisen.
- Abteilungen und Beiträge stehen in Spaltengruppen „(alle)(1..3)"
  (Historie) und „(alle aktuell)(1..3)" (aktuell) mit Abteilungsnummer,
  Abteilungsname, Beitragsnummer, Beitragsname, Betrag, von, bis, Rabatt.
- Abteilungen: **01** Badminton, **09** Fußball, **16** Kegeln,
  **34** Turnen (in Notizen meist „Gymnastik" genannt). Etwa 13 Mitglieder
  sind in zwei Abteilungen.
- Zahlart: 706 „Lastschriftzahler", 10 „Rechnungszahler".
- 14 Mitglieder zahlen über eine andere Person (Ehepartner, Eltern);
  2 Einträge sind reine Zahler-Adressen ohne eigene Mitgliedschaft.
- 15 Übungsleiter mit Lizenznummer (`Uel*`-Spalten).
- Notizfelder sind fast immer befüllt, oft mit Abteilung + aktiv/passiv
  („Fußball-Passiv") oder Funktionen (Wirtschaftshelfer, Bahndienst,
  Jugendtrainer …).

**Alte Beitragslogik:** je Abteilung ein **Grundbeitrag** (Erwachsene
50 €, Kinder/Jugendliche 36 €, Rentner 25 €, Familienkarte 110 €,
Beitragsfrei FK 0 €, Mindestbeitrag 25 €, Beitragsfrei 0 €) plus bei
Aktiven ein Zuschlag **„aktiv"** (24 €). Daraus folgt die Zuordnung auf
die neue Beitragsordnung – und das Aktiv-Häkchen: wer den Zuschlag hat,
ist aktiv.

**Familien-Export (F4 ✅).** Eigene Datei mit den Spalten `FamID`,
`FamName`, `AdrNr`, `Name`, `Straße`, `GebDat`, `Alter`; gebraucht werden
nur `FamID` und `AdrNr`. Befund: 52 Familien mit 167 Personen (2–5 je
Familie), jede mit genau einem Zahler „Familienkarte", alle anderen
„Beitragsfrei FK", niemand in zwei Familien. Rollen beim Import: Zahler
= wer „Familienkarte" hat; übrige unter 18 → Kind; übrige ab 18 →
Elternteil, als Liste zur Prüfung (es könnten volljährige Kinder sein).
4 „Familienkarte"-Zahler und 6 „Beitragsfrei FK" ohne Familie erscheinen
als Hinweis.

**Zuordnung alt → neu (X3 ✅).** Alle 12 Kombinationen, die im Export
vorkommen (über alle Abteilungen eines Mitglieds zusammengefasst):

| alt (Grundbeitrag + Zuschlag) | Anzahl | neue Beitragsart | Aktiv-Häkchen |
|---|---:|---|---|
| Erwachsene + aktiv | 110 | Aktiv (85 €) | aktiv |
| Erwachsene | 134 | Passiv (50 €) | passiv |
| Kinder | 140 | Jugend/Schüler/Studenten/Azubis (45 €) | aktiv |
| Jugendliche | 17 | Jugend/Schüler/Studenten/Azubis (45 €) | aktiv |
| Rentner + aktiv | 1 | Rentner aktiv (50 €) | aktiv |
| Rentner | 80 | Rentner passiv (30 €) | passiv |
| Familienkarte | 56 | Familie (130 €), Zahler der Familie | aus Notiz¹ |
| Beitragsfrei FK | 121 | Mitglied einer Familie, kein eigener Beitrag | aus Notiz¹ |
| Mindestbeitrag + aktiv | 11 | Mindestbeitrag | aktiv |
| Mindestbeitrag | 42 | Mindestbeitrag | passiv |
| Beitragsfrei | 4 | Beitragsfrei, Grund aus der Notiz | aktiv |
| (keine Mitgliedschaft) | 2 | kein Mitglied – nur Kontoinhaber der Mitglieder, für die gezahlt wird | – |

¹ „aktiv", wenn die Notiz „aktiv" enthält, sonst passiv; Mirko prüft die
Liste nach dem Import.

- Ein **befristeter Rabatt** (4 Mitglieder, 50 %) wird nicht übernommen
  (BT8 ✅): voller Beitrag der neuen Beitragsart.
- Ist ein Mitglied in mehreren Abteilungen (11), werden alle Abteilungen
  übernommen, die Beitragsart einmal.
- Kinder/Jugendliche über 18 bzw. Rentner unter 67 erscheinen nach dem
  Import als Hinweis („Hinweise statt Sperren").

Zuordnung (Vorschlag):

| Spalte(n) alt | Ziel | Bemerkung |
|---|---|---|
| `Adressnummer` | Mitgliedsnummer | `LfdNr` wird nicht gebraucht (X2 ✅) |
| `Anrede`, `Vorname`, `Nachname`, `Titel` | Name | |
| `Geschlecht` (m/w) | Geschlecht | für BLSV |
| `GebDatum` | Geburtsdatum | `AlterInJahren`, `GebSpezial` werden nicht gebraucht |
| `Strasse`, `Plz`, `Ort`, `Land`/`Lkz` | Anschrift | |
| `Telefon`, `Handy`, `EMail` | Kontakt | |
| `Konto1Iban`, `Konto1Bic` | IBAN, BIC | |
| `ZahlerVorname`/`ZahlerNachname`, `MitZahlerIban` | Kontoinhaber, falls abweichend | nur wenn Zahler ≠ Mitglied |
| `EintrittsDatum`, `AustrittsDatum`, `Austrittsgrund` | Eintritt/Austritt | |
| `Zahlart` | Lastschrift ja/nein | „Rechnungszahler" → selbst kassieren |
| `Abteilungsnummer/-name (alle aktuell)(1..3)` | Abteilungen | |
| `Beitragsname (alle aktuell)(1..3)` | Beitragsart + aktiv/passiv | Zuordnungstabelle oben |
| `AdrNotizen`, `MitNotizen` | Bemerkung | vollständig übernommen (X6 ✅) |
| `Multifeld 1`, `Multifeld 1_` (Kürzel/Zahlen, selten befüllt) | – | Bedeutung unbekannt, wird nicht übernommen (X4 ✅) |
| `Post*`, `Zahler*`-Anschrift, `Konto2/3*`, `Fax*`, `*Dienst`, `Homepage`, `Nation`, `GeburtsOrt`, `Sortierfeld`, `Wahlfeld*`, `Zeitung`, `Helfer`, `Mahnstufe`, `offenerPosten*`, `UnbedenkBesch*`, `Uel*` (Übungsleiter) | – | nicht übernommen (in der Beispieldatei leer, 0 oder doppelt) |

Offen zum Import ist nur noch E6.

## 9. Versionen je Mitglied

- **muss** Jede Änderung an einem Mitglied erzeugt eine neue **Version**
  mit allen Werten (alte Werte bleiben erhalten), wer und wann.
- **muss** Versionsliste am Mitglied mit Anzeige, was sich gegenüber der
  vorherigen Version geändert hat.
- **muss** Zurück auf eine alte Version: deren Werte werden als **neue
  Version hinten angehängt** („wiederhergestellt aus Version 3") – es wird
  nie etwas überschrieben oder gelöscht.
- Inhalt einer Version: alle Felder am Mitglied (§2) einschließlich
  Abteilungen, Funktionen, Beitragsart, Familie und Rolle.
  **Nicht** versioniert, weil sie selbst schon Verlauf sind: Jahresbeiträge
  und Einzüge, Nachweis-Vermerke, Ehrungs-Vermerke.

- **muss** Gibt es die Familie einer zurückgeholten Version nicht mehr,
  bleibt die Familie leer, mit Hinweis (V1 ✅).

## 10. Bewusst nicht (vorerst)

- Rundmails, Serienbriefe, Mitgliedsausweise – später möglich; bis dahin
  gefilterte Liste exportieren.
- Kopplung an Vereinsbelege oder Vereinskalender – die
  Mitgliederverwaltung bleibt eigenständig (E-F4 ✅).
- Zugang für Mitglieder, öffentliche Formulare (E-F2/E-F3 ✅).
