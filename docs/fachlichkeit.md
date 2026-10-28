# Fachlichkeit – Sammlung (Brainstorm)

Arbeitsstand der fachlichen Anforderungen. Hier wird gesammelt, sortiert
und geklärt; erst wenn ein Bereich geklärt ist, wird daraus eine Spec unter
`docs/spec/`. Einstufung: **muss** · **soll** · **später** · **nicht**.
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

Offen:
- B2: Was genau ist „keine Finanzen" für den Vorstand? *Vorschlag:* er
  darf Mitglieder anlegen und bearbeiten (inkl. IBAN, Abteilung,
  Beitragsart), sieht aber keine Einzüge und kann nichts auf „bezahlt"
  setzen oder Lastschriftdateien erzeugen.

## 2. Mitglieder – Stammdaten

- **muss** Klassische Stammdaten: Name, Vorname, Anschrift, Telefon,
  E-Mail, Geburtsdatum, IBAN, Eintrittsdatum.
- **muss** Suche nach Mitgliedern.
- **muss** Aktiv/passiv – passive Mitglieder werden ebenfalls geführt.
- **muss** Geschlecht (gebraucht für die BLSV-Meldung, §6).

- **muss** Mitgliedsnummer aus der alten Software wird übernommen; neue
  Mitglieder bekommen die nächste freie Nummer (M1 ✅).
- **muss** Austritt mit Datum und Grund (z. B. „verstorben"); Ehemalige
  bleiben gespeichert, standardmäßig ausgeblendet, Löschen nur von Hand
  (M2/M3 ✅).
- **muss** Optionales Feld „Kontoinhaber, falls abweichend" (M4 ✅).

Offen:
- M5: Weitere Felder (zweite Telefonnummer, Bemerkung, Ehrungen,
  Funktionen im Verein …)? *Vorschlag:* eine Freitext-Bemerkung, sonst
  nichts – weitere Felder legen wir beim Import-Mapping fest.

## 3. Abteilungen

- **muss** Abteilungen frei einrichtbar (heute: Fußball, Gymnastik,
  Kegeln, Badminton), je mit **BLSV-Nummer** (Sportart/Fachverband).
- **muss** Zuordnung eines Mitglieds zu Abteilungen ist optional
  (z. B. sehr alte Mitglieder ohne bekannte Abteilung); ein Mitglied kann
  in **mehreren** Abteilungen sein (A1 ✅).

Offen:
- A2: Passive Mitglieder – haben sie trotzdem eine Abteilung?
  *Vorschlag:* dürfen, müssen nicht.

## 4. Familien

- **muss** Familie = Gruppe von Mitgliedern, eine Person ist
  **Zahler** (häufig der Vater), die anderen hängen daran und zahlen
  keinen eigenen Beitrag.
- **muss** Kinder sind bis 18 in der Familienkarte berechtigt.
- **muss** Regeln nur als Hinweis (Leitlinie „Hinweise statt Sperren"):
  - Familie hat weniger als **2** Personen (F1 ✅),
  - Mitglieder einer Familie wohnen nicht an derselben Anschrift,
  - ein Kind in der Familie ist 18 oder älter – nur Hinweis, Umstellung
    von Hand (F3 ✅).

Offen:
- F2: Wer darf alles zur Familie gehören – nur Eltern/Partner und Kinder
  unter 18, oder auch Großeltern, volljährige Kinder?
  *Vorschlag:* jeder darf hinzugefügt werden; Hinweis nur bei Kindern
  ≥ 18.
- F4: Woher kennt die alte Software die Zusammengehörigkeit einer
  Familie? Im Export zahlt jede Person „selbst"; erkennbar ist nur die
  Beitragsart („Familienkarte" beim Zahler, „Beitragsfrei FK" bei den
  anderen). *Vorschlag:* beim Import Familien über gleiche Anschrift
  vorschlagen, du bestätigst sie in einer Liste.

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

- **muss** Beitragsarten und Beträge im Admin pflegbar; jede Beitragsart
  ist jedem Mitglied zuweisbar, unabhängig vom Alter (Hinweis, keine
  Sperre – z. B. 9-Jähriger als Rentner).
- **muss** Beitrag ist jährlich, Stichtag 1.1.
- **muss** Eintritt im laufenden Jahr: Beitrag anteilig auf das Restjahr
  heruntergerechnet.
- **muss** Nachweise (Rente, Studium, Ausbildung): Vermerk mit
  Ablaufdatum, **wer** ihn wann gemacht hat, optional eine Datei (Scan)
  dazu – die Datei ist keine Pflicht (BT2 ✅). Abgelaufene Nachweise
  erzeugen einen Hinweis.
- **muss** Anteiliger Beitrag bei Eintritt im laufenden Jahr wird
  **vorgeschlagen** (nach angefangenen Monaten, Eintritt im Mai → 8/12)
  und ist von Hand änderbar (BT1 ✅). Bei ~700 Mitgliedern betrifft das
  nur wenige Fälle im Jahr.

Offen:
- BT3: Beitragsänderung – gilt ein neuer Betrag ab dem nächsten
  Beitragsjahr, bereits erzeugte Jahresbeiträge bleiben unverändert?
  *Vorschlag:* ja.
- BT4 (neu formuliert): Woran erkennt man, ob jemand aktiv oder passiv
  ist? Die Beitragsart verrät es nur teilweise („Passiv", „Rentner
  passiv"), bei „Jugend" und „Familie" nicht. Beispiel: Die Mutter in
  einer Familienkarte macht keinen Sport – ist sie passiv? *Vorschlag:*
  „aktiv/passiv" ist ein eigenes Häkchen am Mitglied, unabhängig von der
  Beitragsart. Passt beides nicht zusammen (Häkchen „aktiv", Beitragsart
  „Passiv"), gibt es einen Hinweis.
- BT5: Die alte Software rechnet Beiträge **je Abteilung** (wer in zwei
  Abteilungen ist, hat zwei Beiträge). Die neue Beitragsordnung ist ein
  Beitrag **je Mitglied**, egal wie viele Abteilungen. Stimmt das, und
  gibt es keine zusätzlichen Abteilungsbeiträge mehr? *Vorschlag:* ja,
  ein Beitrag je Mitglied.

### Einzug und Bezahlt-Status

- **muss** Auf Knopfdruck eine **einfache SEPA-Lastschriftdatei**
  (pain.008) für die Sparkasse Aschaffenburg Miltenberg erzeugen.
- **muss** Der Einzug wird je Mitglied gemerkt: Datei erzeugen → Status
  „im Einzug" → nach Bestätigung, dass eingezogen wurde → „bezahlt".
- **muss** Jedes Mitglied jederzeit manuell auf „bezahlt" setzbar
  (Barzahlung, Überweisung).
- **nicht** SEPA-Mandate einholen und verwalten. Rücklastschriften klärt
  der Vorstand persönlich.
- Technisch verlangt die Datei je Mitglied eine Mandatsreferenz und ein
  Mandatsdatum sowie die Gläubiger-ID des Vereins. Einfach gelöst:
  Mandatsreferenz = Mitgliedsnummer, Mandatsdatum = Eintrittsdatum bzw.
  einstellbares Datum, Gläubiger-ID als Einstellung. Vorhandene Werte aus
  der alten Software werden übernommen.
- Vorlage für den Code: Vereinsbelege erzeugt schon pain.001-Dateien
  (Überweisungen); pain.008 folgt demselben Muster.

Offen:
- **muss** Der Verein hat eine Gläubiger-ID, die alte Software hat
  bereits Lastschriften erzeugt (E1 ✅).
- **muss** Rücklastschrift: Mitglied von Hand zurück auf „offen", mit
  Vermerk (E3 ✅).
- **muss** Mitglieder ohne IBAN bzw. „Rechnungszahler" erscheinen in einer
  eigenen Liste „selbst kassieren" (E4 ✅).

Offen:
- E6: Der Export der alten Software enthält **keine Mandatsreferenz**.
  Welche Referenz stand in den bisherigen Lastschriftdateien? Am
  einfachsten: in einer alten Einzugsdatei bzw. im Online-Banking bei
  einer Lastschrift nachsehen, wie die Mandatsreferenz aussieht (nur das
  Muster, z. B. „Mitgliedsnummer" oder „VEREIN-0123"). Bleibt die Referenz
  gleich, sieht die Bank keinen Unterschied.
- E2: Welches Dateiformat nimmt die Sparkasse an (Online-Banking-Upload
  bzw. Banking-Programm)? *Vorschlag:* pain.008.001.08 (aktueller
  DK-Standard), notfalls .02 – mit einer Testdatei klären.
- E5: Einzugstermin – ein fester Termin im Jahr (z. B. Februar) plus
  einzelne Nachzügler für Neueintritte? *Vorschlag:* ja; ein Einzugslauf
  umfasst alle offenen Beiträge mit IBAN, die du auswählst.

## 6. Geburtstagskalender

- **muss** Übersicht, wer wann Geburtstag hat, mit Abteilung.

Offen:
- G1: Form – Liste je Monat (z. B. „nächste 30 Tage" + Monatsauswahl)?
  Runde Geburtstage hervorheben? *Vorschlag:* Liste der nächsten Wochen
  und je Monat, runde Geburtstage markiert, druckbar.
- G2: Ehemalige und Verstorbene ausblenden? *Vorschlag:* ja.

## 7. BLSV-Meldung

- **muss** Jährliche Meldung aller Mitglieder an den BLSV als Export im
  Format der BLSV-Excel-Vorlage; Abteilungen mit ihrer BLSV-Nummer.

Offen:
- L1: Vorlage – Mirko stellt die BLSV-Excel-Datei bereit (ohne echte
  Mitgliederdaten).
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

Offen:
- X1: Echtes `.xlsx` oder reicht CSV? Mirko prüft das (u. a. was der
  BLSV annimmt). *Vorschlag bis dahin:* `.xlsx` für beide Exporte.

### Altdatei: Export der bisherigen Software

Analysiert wurde nur der **Aufbau** einer Beispieldatei (Spaltennamen,
Formate). Die Datei selbst und alle Inhalte bleiben außerhalb des Repos.

- Ein Blatt, **167 Spalten**, eine Zeile je Mitglied. Datumswerte als
  Text `TT.MM.JJJJ`, IBAN mit Leerzeichen gruppiert, Telefonnummern in
  freien Schreibweisen.
- Abteilungen und Beiträge stehen in Spaltengruppen „(alle)(1..3)"
  (Historie) und „(alle aktuell)(1..3)" (aktuell) mit Abteilungsnummer,
  Abteilungsname, Beitragsnummer, Beitragsname, Betrag, von, bis, Rabatt.
- Bekannte Werte: Zahlart „Lastschriftzahler"/„Rechnungszahler";
  Beitragsnamen u. a. „aktiv", „Erwachsene", „Familienkarte",
  „Beitragsfrei FK"; Abteilungen u. a. „Fußball" (09), „Turnen" (34).

Zuordnung (Vorschlag):

| Spalte(n) alt | Ziel | Bemerkung |
|---|---|---|
| `Adressnummer` bzw. `LfdNr` | Mitgliedsnummer | offen, welche (X2) |
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
| `Beitragsname (alle aktuell)(1)` | Beitragsart | über Zuordnungstabelle alt → neu (X3) |
| `AdrNotizen`, `MitNotizen` | Bemerkung | |
| `Multifeld 1` (Werte A/H/I) | ? | Bedeutung offen (X4) |
| `Post*`, `Zahler*`-Anschrift, `Konto2/3*`, `Fax*`, `*Dienst`, `Homepage`, `Nation`, `GeburtsOrt`, `Sortierfeld`, `Wahlfeld*`, `Zeitung`, `Helfer`, `Mahnstufe`, `offenerPosten*`, `UnbedenkBesch*`, `Uel*` (Übungsleiter) | – | nicht übernommen (in der Beispieldatei leer, 0 oder doppelt) |

Offen:
- X2: Welche Nummer ist die Mitgliedsnummer – `Adressnummer` oder
  `LfdNr`? (Sie sind nicht immer gleich.)
- X3: Zuordnung alte → neue Beitragsarten (z. B. „Erwachsene" → „Aktiv"?
  „Beitragsfrei FK" → Mitglied einer Familie ohne eigenen Beitrag).
  Machen wir mit der Liste aller alten Beitragsnamen.
- X4: Was bedeuten die Werte A/H/I in `Multifeld 1`, und werden sie
  gebraucht?

## 9. Noch nicht besprochen

Bewusst gesammelt, damit nichts verloren geht – jeweils nur, wenn ihr es
braucht:
- Kommunikation (Rundmail an Abteilung, Serienbrief, Mitgliedsausweis)
- Ehrungen/Jubiläen (25/40/50 Jahre Mitgliedschaft)
- Funktionen im Verein (Übungsleiter, Abteilungsleiter)
- Änderungsprotokoll je Mitglied („wer hat wann die IBAN geändert")
- Kopplung an Vereinsbelege oder Vereinskalender (E-F4)
