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
  (Hauptnutzer: Mirko).
- **nicht** Zugriff für Mitglieder selbst (E-F2: geklärt, vorerst nein).
- **nicht** Öffentliche Seiten außer Anmeldung, Impressum, Datenschutz
  (E-F3: geklärt, vorerst nein).

Offen:
- B1: Brauchen die Vorstände unterschiedliche Rechte?
  *Vorschlag:* nein – alle dürfen alles, nur die Benutzerverwaltung ist
  dem Admin vorbehalten.

## 2. Mitglieder – Stammdaten

- **muss** Klassische Stammdaten: Name, Vorname, Anschrift, Telefon,
  E-Mail, Geburtsdatum, IBAN, Eintrittsdatum.
- **muss** Suche nach Mitgliedern.
- **muss** Aktiv/passiv – passive Mitglieder werden ebenfalls geführt.
- **muss** Geschlecht (gebraucht für die BLSV-Meldung, §6).

Offen:
- M1: Mitgliedsnummer – gibt es eine aus der alten Software, die weiter
  gilt? *Vorschlag:* ja, übernehmen; neue Mitglieder bekommen die nächste
  freie Nummer.
- M2: Austritt – Austrittsdatum erfassen und das Mitglied als „ehemalig"
  behalten (nicht löschen)? *Vorschlag:* ja; Ehemalige standardmäßig
  ausgeblendet, Löschen nur von Hand.
- M3: Verstorbene – eigener Status oder Austritt mit Grund?
  *Vorschlag:* Austritt mit Grund „verstorben".
- M4: Kontoinhaber – zahlt das Mitglied immer vom eigenen Konto, oder gibt
  es abweichende Kontoinhaber (Eltern zahlen für Kinder)?
  *Vorschlag:* optionales Feld „Kontoinhaber, falls abweichend".
- M5: Weitere Felder (zweite Telefonnummer, Bemerkung, Ehrungen,
  Funktionen im Verein …)? *Vorschlag:* eine Freitext-Bemerkung, sonst
  nichts – weitere Felder legen wir beim Import-Mapping fest.

## 3. Abteilungen

- **muss** Abteilungen frei einrichtbar (heute: Fußball, Gymnastik,
  Kegeln, Badminton), je mit **BLSV-Nummer** (Sportart/Fachverband).
- **muss** Zuordnung eines Mitglieds zu einer Abteilung ist optional
  (z. B. sehr alte Mitglieder ohne bekannte Abteilung).

Offen:
- A1: Kann ein Mitglied in **mehreren** Abteilungen sein (Fußball und
  Badminton)? Für die BLSV-Meldung zählt es dann je Sportart.
  *Vorschlag:* ja, 0..n Abteilungen.
- A2: Passive Mitglieder – haben sie trotzdem eine Abteilung?
  *Vorschlag:* dürfen, müssen nicht.

## 4. Familien

- **muss** Familie = Gruppe von Mitgliedern, eine Person ist
  **Zahler** (häufig der Vater), die anderen hängen daran und zahlen
  keinen eigenen Beitrag.
- **muss** Kinder sind bis 18 in der Familienkarte berechtigt.
- **muss** Regeln nur als Hinweis (Leitlinie „Hinweise statt Sperren"):
  - Familie hat zu wenige Personen,
  - Mitglieder einer Familie wohnen nicht an derselben Anschrift,
  - ein Kind in der Familie ist 18 oder älter.

Offen:
- F1: Mindestgröße – du schreibst einmal „mindestens 2 Personen", einmal
  „eigentlich mindestens 3". Was gilt? *Vorschlag:* 2 (so steht es in der
  Beitragsordnung „ab 2 Personen in einem Haushalt").
- F2: Wer darf alles zur Familie gehören – nur Eltern/Partner und Kinder
  unter 18, oder auch Großeltern, volljährige Kinder?
  *Vorschlag:* jeder darf hinzugefügt werden; Hinweis nur bei Kindern
  ≥ 18.
- F3: Wird ein Kind 18, soll es automatisch aus der Familie fallen oder
  nur ein Hinweis kommen? *Vorschlag:* nur Hinweis in „Auffälligkeiten";
  du entscheidest und stellst das Kind auf einen eigenen Beitrag um.

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
- **muss** Nachweise (Rente, Studium, Ausbildung) werden vermerkt.

Offen:
- BT1: Anteilig – nach Monaten oder nach Tagen? *Vorschlag:* nach
  angefangenen Monaten (Eintritt im Mai → 8/12), auf volle Cent gerundet;
  der Betrag ist vor dem Einzug von Hand änderbar.
- BT2: Nachweis – reicht ein Vermerk „Nachweis liegt vor bis
  <Datum>", oder soll das Dokument selbst (Scan) gespeichert werden?
  *Vorschlag:* nur Vermerk mit Ablaufdatum; abgelaufene Nachweise
  erscheinen als Hinweis.
- BT3: Beitragsänderung – gilt ein neuer Betrag ab dem nächsten
  Beitragsjahr, bereits erzeugte Jahresbeiträge bleiben unverändert?
  *Vorschlag:* ja.
- BT4: Sind aktiv/passiv und die Beitragsart dasselbe, oder getrennt?
  (Ein passives Mitglied mit Beitragsart „Familie"?) *Vorschlag:*
  getrennt; Hinweis, wenn sie nicht zusammenpassen.

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
- E1: Gläubiger-ID – hat der Verein eine? Hat die alte Software schon
  Lastschriften erzeugt (dann gibt es dort Mandatsreferenzen)?
- E2: Welches Dateiformat nimmt die Sparkasse an (Online-Banking-Upload
  bzw. Banking-Programm)? *Vorschlag:* pain.008.001.08 (aktueller
  DK-Standard), notfalls .02 – mit einer Testdatei klären.
- E3: Rücklastschrift – soll ein Mitglied von „bezahlt" zurück auf
  „offen" gesetzt werden können? *Vorschlag:* ja, von Hand, mit Vermerk.
- E4: Mitglieder ohne IBAN – erscheinen in einer Liste „nicht im Einzug,
  bitte selbst kassieren"? *Vorschlag:* ja.
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
- L2: Stichtag der Meldung (1.1.?) und wer zählt mit (passive Mitglieder?
  Mitglieder ohne Abteilung?).

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
- X1: Echtes `.xlsx` oder reicht CSV (öffnet Excel auch)? Für die
  BLSV-Vorlage wird vermutlich ohnehin `.xlsx` gebraucht. *Vorschlag:*
  `.xlsx` für beide Exporte, Import als CSV.

## 9. Noch nicht besprochen

Bewusst gesammelt, damit nichts verloren geht – jeweils nur, wenn ihr es
braucht:
- Kommunikation (Rundmail an Abteilung, Serienbrief, Mitgliedsausweis)
- Ehrungen/Jubiläen (25/40/50 Jahre Mitgliedschaft)
- Funktionen im Verein (Übungsleiter, Abteilungsleiter)
- Änderungsprotokoll je Mitglied („wer hat wann die IBAN geändert")
- Kopplung an Vereinsbelege oder Vereinskalender (E-F4)
