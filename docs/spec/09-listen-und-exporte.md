# 09 – Listen und Exporte

Quelle: `docs/fachlichkeit.md` §6–8. Alle Listen liegen auf **einer**
Seite „Listen" (06 §2) mit den Listen als Reitern oben, wenigen Filtern
und einem Export-Knopf. `/app/listen` zeigt sofort die Geburtstage (hat
das Konto diese Liste nicht, die erste, die es sehen darf); eine eigene
Übersichtsseite gibt es nicht. Ehemalige sind überall ausgeblendet, außer beim
Gesamtexport mit Häkchen „Ehemalige einschließen".

## 1. Gemeinsame Regeln

- Jede Liste: Tabelle am PC, Karten am Handy, Klick auf eine Zeile
  öffnet das Mitglied **in der Listenseite selbst** (Baustein aus 06 §5:
  links die Liste, rechts die Person mit allen Reitern und „Schließen";
  am Handy als eigene Ansicht, im Barrierefrei-Modus über die ganze Breite statt der Liste).
  Adresse: `/app/listen/<liste>?<Filter>&mitglied=<id>&reiter=<reiter>` –
  Filter, Zeitraum, Sortierung und Seite bleiben beim Öffnen, Sortieren,
  Blättern und nach dem Speichern erhalten; die Formulare der Person
  leiten mit `zurueck` auf diese Adresse zurück. Kein Wechsel auf die
  Mitglieder-Seite. Wer `mitglieder.sehen` nicht hat, sieht die Namen ohne
  Link.
- **Export** jeder Liste wahlweise als **xlsx** oder **csv**, mit genau
  den Spalten und Filtern der Ansicht.
  - csv: UTF-8 mit BOM, Trennzeichen `;`, Datum `TT.MM.JJJJ` (öffnet in
    deutschem Excel direkt richtig).
  - xlsx: ein Blatt, erste Zeile Spaltennamen fett und fixiert, Datum als
    echtes Excel-Datum, Beträge und Zahlen als Zahlen, Text als
    Inline-Strings (nie als Formel). Eigener kleiner Writer in reinem PHP
    (`App\Service\Export\XlsxWriter` + `ZipWriter`, wenige XML-Dateien)
    statt einer großen Bibliothek (05 §5). Das ZIP entsteht **im
    Arbeitsspeicher** statt per `ZipArchive`: das schreibt nur in Dateien,
    und ein Export liegt nie auf dem Webspace (CLAUDE.md §4).
  - Dateiname ohne Personendaten, z. B. `geburtstage-2027-03.xlsx`.
- Exporte sind **unverschlüsselt** – darauf weist der Knopf hin
  („Die Datei enthält Mitgliederdaten im Klartext"). Jeder Export wird
  im Audit-Log vermerkt (wer, wann, welche Liste, wie viele Zeilen).
- Listen filtern und sortieren in SQL; nur Altersberechnungen
  (Geburtstage, Ehrungen) rechnet PHP.
- Die Seite sortiert zusätzlich per Klick auf die Spaltenüberschrift
  (in PHP über die gelieferten Zeilen, leere Zellen zuletzt) und blättert
  in Seiten zu 50. Der Export enthält immer **alle** Zeilen der Auswahl in
  der angezeigten Reihenfolge.
- Der Export ist ein `POST` (`/app/listen/<liste>/export`, CSRF-geschützt):
  er schreibt einen Audit-Eintrag und darf nicht durch einen vorgeladenen
  Link ausgelöst werden. Format (`xlsx` Standard, `csv`) und die Filter der
  Ansicht kommen aus dem Formular. Der Audit-Eintrag (`export.erzeugt`,
  Entität `liste`, ID = Schlüssel der Liste) trägt nur Format und
  Zeilenzahl.

### Eine Liste hinzufügen

Eine Liste ist eine Klasse `app/src/Listen/<Name>Liste.php`, die `Liste`
implementiert (05 §6) und nur sagt, **was** sie zeigt: `filter()`
(`ListenFilter::auswahl/haken/jahr/datum`; Werte kommen immer validiert an),
`ergebnis($filter, $konto)` mit `Spalte`n (Text, Datum, Zahl, Geld in Cent),
`ListenZeile`n (Werte nach Spaltenschlüssel, Mitglied-ID für den Klick,
optional eine Marke wie „rund"), Hinweisen über der Tabelle und optional einer
`ListenNavigation` (Zurück/Vor durch Zeiträume), sowie `dateiname()` (ohne
Personendaten). Seite, Sortierung, Blättern und Export sind für alle gleich
(`ListenController`, `ListenAnsicht`, `ListenExport`). Was eine Rolle nicht
sehen darf (Beitragsspalten), lässt `ergebnis()` anhand des Kontos weg –
Ansicht und Export zeigen genau das, was es liefert.

## 2. Die Listen

| Liste | Inhalt | Filter | Rolle |
|---|---|---|---|
| **Geburtstage** | Name, Geburtstag, neues Alter, Abteilungen; runde Geburtstage markiert | Zeitraum: Woche / Monat / Jahr (mit Vor/Zurück), „nur runde" | alle |
| **Ehrungen** | Name, Eintrittsdatum, Jahre, Stufe, Abteilungen, geehrt am | Jahr, „nur offene" | alle |
| **Funktionen** | Name, Funktion, Lizenznummer, Abteilungen, Kontakt | Funktion | alle |
| **Selbst kassieren** | offene Jahresbeiträge mit Zahlart `selbst` oder ohne gültige IBAN: Name, Jahr, Betrag, Kontakt | Jahr | Admin, Nur lesen |
| **Auffälligkeiten** | alle Hinweise aller Mitglieder (07 §6): Name, Hinweis | Art des Hinweises | alle |
| **BLSV-Meldung** | §4 | Stichtag | alle |
| **Alle Mitglieder** | Gesamtexport §5 | Ehemalige einschließen | alle (Beitragsspalten nur Admin und Nur lesen) |

### Auffälligkeiten

- Eine Zeile je Hinweis, Filter nach Art (`HinweisArt`). Dazu gehören seit
  #108 „Anschrift auf der Karte nicht gefunden" und „Anschrift auf der Karte
  nur ungefähr gefunden" (07 §6): Mirko sieht hier, welche Anschriften er
  auf der Karte noch korrigieren muss.
- Seit #133 zusätzlich die Art **„Rücklastschrift"**: je aktuellem
  Mitglied mit Rücklastschrift eine Zeile „Rücklastschrift in 2025, 2027"
  (08 §2), nur mit `beitraege.sehen`. Sie ist kein Hinweis am Mitglied
  (07 §6), sondern kommt aus `JahresbeitragRepository::
  ruecklastschriftJahre()`; die Zeilen werden mit den Hinweisen nach Namen
  sortiert und öffnen den Reiter „Daten".
- Eine Zeile kann das Mitglied auf einem bestimmten Reiter öffnen
  (`ListenZeile::$reiter`): die beiden Karten-Hinweise öffnen es im Reiter
  „Daten", wo der Block „Wohnort auf der Karte" steht.

### Geburtstage

- Runde Geburtstage: Liste der Alter in den Einstellungen (Default
  18, 30, 40, 50, 60, 65, 70, 75, 80, 85, 90, 95, 100).
- Geburtstag am 29.02. zählt in Nicht-Schaltjahren am 28.02.

### Ehrungen

- Ehrungsstufen in den Einstellungen als Jahre (Default 25, 40, 50, 60,
  70).
- Ein Mitglied steht im Jahr J in der Liste, wenn
  `J − Eintrittsjahr` eine Stufe ist. Unterbrechungen zählen nicht;
  unbekanntes Eintrittsdatum ⇒ nicht in der Liste (Hinweis, 07 §6).
- Knopf **„geehrt"** je Zeile: vermerkt Stufe, Datum, wer. „Nur offene"
  blendet Geehrte aus. Vermerk zurücknehmbar.
- Das Datum des Vermerks ist der Tag des Klicks. Wer vor dem 1.1. des
  Jahres ausgetreten ist, steht nicht in der Liste. Mitglieder ohne
  Eintrittsdatum zählt ein Hinweis über der Tabelle (ohne Namen). Der Knopf
  ist eine `ListenAktion` an der Zeile (nur in der Ansicht, nicht im Export);
  er postet an `/app/listen/ehrungen/vermerk` (Admin und Vorstand; „Nur
  lesen“ sieht die Liste ohne Knöpfe).

## 3. Einstellungen dazu

Runde Geburtstage (Liste von Zahlen), Ehrungsstufen (Liste von Zahlen),
Funktionen (07 §4).

## 4. BLSV-Meldung

Export im Format der BLSV-Vorlage „Mitgliederimport" (BE2025), ein Blatt,
Spalten in dieser Reihenfolge:

`Titel` · `Name` · `Vorname` · `Namenszusatz` · `Geschlecht` ·
`Geburtsdatum` · `Spartenkennzeichen`

- `Spartenkennzeichen` = BLSV-Nummer der Abteilung (07 §2).
- Mitglieder ohne Geschlecht oder Geburtsdatum stehen trotzdem in der
  Datei und zusätzlich in einer Hinweisliste über dem Export.
- **Noch offen (L3, Mirko liefert)**: Werte für Geschlecht, Datumsformat,
  eine Zeile je Mitglied oder je Mitglied und Sparte, Mitglieder ohne
  Abteilung, Stichtag. Bis dahin: eine Zeile je Mitglied und Abteilung,
  Mitglieder ohne Abteilung mit leerem Spartenkennzeichen, Geschlecht
  `m`/`w`/`d`, Datum als echtes Excel-Datum, Stichtag 1.1. des Jahres.
  Diese Punkte als Konstanten an **einer** Stelle
  (`App\Service\Export\BlsvFormat`), damit die Anpassung klein bleibt.
- Umsetzung `BlsvListe`: Stichtag-Filter (leer = 1.1. des laufenden Jahres);
  aufgenommen sind Mitglieder, die am Stichtag eingetreten (oder ohne
  Eintrittsdatum) und noch nicht ausgetreten sind. Die Hinweise über der
  Tabelle nennen die BLSV-Vereinsnummer (11 §3) und die Mitgliedsnummern ohne
  Geschlecht bzw. Geburtsdatum.

## 5. Gesamtexport „Alle Mitglieder"

Jederzeit, für einen späteren Umzug zu anderer Software: **eine Zeile je
Mitglied, alle Felder** aus 07 §1 in lesbarer Form – Abteilungen und
Funktionen als kommagetrennte Namen, Familie als Name + Rolle, Beitragsart
als Name. Admin bekommt zusätzlich je Mitglied den Status des laufenden
Jahresbeitrags. Nachweis-Dateien und Versionen sind nicht enthalten (dafür
gibt es das Backup).

Umsetzung `AlleMitgliederListe`. Als **Beitragsspalten** (nur mit dem Recht
`beitraege.sehen`, also alle drei Rollen) gelten Beitragsart, Zahlart, IBAN, BIC,
Kontoinhaber, Mandatsreferenz, Mandatsdatum sowie Betrag und Status des
Jahresbeitrags des laufenden Jahres.

## 6. Datenmodell

| Tabelle | Spalten |
|---|---|
| `ehrung` | id, mitglied_id, stufe_jahre, geehrt_am, vermerkt_von |
| `setting` | `runde_geburtstage`, `ehrungsstufen` (JSON-Listen) |

## 7. Statistik (#127)

Eigener Hauptmenüpunkt **Statistik** (`/app/statistik`, 06 §2) mit zwei
Reitern. Nur Zahlen, keine Namen, kein Export. Je Auswertung eine Tabelle
(`App\Service\Statistik\Auswertung`, ein Template
`app/views/statistik/auswertung.php`) und darüber ein Diagramm, das
`public/js/statistik.js` mit Chart.js (vendored, `public/js/vendor/README.md`)
**aus der Tabelle** zeichnet (`data-diagramm`, `th[data-reihe]`,
`td[data-wert]` mit Rohwerten – Cent bzw. Anzahl); danach klappt die Tabelle
zu („Zahlen als Tabelle"). Ohne JS und im Barrierefrei-Modus bleiben nur die
offenen Tabellen. Farben: vier Tokens `--diagramm-1…4` in
`public/css/teile/statistik.css` (hell/dunkel; 4 = Grau für „ohne Angabe",
„Sonstige"), beim Wechsel hell/dunkel neu gezeichnet. Chart.js und das Skript
lädt nur diese Seite.

**Reiter „Mitglieder"** (`listen.sehen`, alle drei Rollen;
`App\Service\Statistik\Mitgliederstatistik`). Stichtag ist heute; gezählt
werden die aktuellen Mitglieder – Ehemalige (`istEhemalig(heute)`) nirgends,
außer bei den Austritten.
- Kennzahlen: Mitglieder, aktiv, passiv, Durchschnittsalter (nur mit
  Geburtsdatum, eine Nachkommastelle), Kinder und Jugendliche unter 18,
  weiblich, männlich, divers, Geschlecht ohne Angabe.
- **Altersgruppen** (zentral in `App\Service\Statistik\Altersgruppen`):
  0–6, 7–14, 15–18, 19–26, 27–40, 41–60, 61–70, 71 und älter, ohne
  Geburtsdatum. Alter in vollen Jahren (`Alter::am`), **beide Grenzen
  gehören dazu** („15–18" = 15 bis einschließlich 18); „unter 18" ist eine
  eigene Kennzahl und folgt den Gruppen nicht. Je Gruppe gestapelt nach
  Geschlecht.
- **Kinder/Jugendliche/Erwachsene/Senioren**: 0–13, 14–17, 18–66, ab 67
  (Rentenalter wie die Hinweise, 07 §6).
- **Abteilungen**: je Abteilung nach Geschlecht und Ø Alter; wer in mehreren
  ist, zählt in jeder (keine Summenzeile), ohne Abteilung eigene Zeile.
  Aktive Abteilungen immer, inaktive nur mit Mitgliedern.
- **Wohnorte** und **Postleitzahlen**: die 10 häufigsten (gleich viele: nach
  Name), der Rest als „Sonstige (n Orte)", leere als „ohne Angabe"; Ort ohne
  Groß-/Kleinschreibung und Leerzeichen am Rand zusammengefasst.
- **Familien und Einzelmitglieder** (Ring) mit der Zahl der Familien.
- **Mitgliedsdauer** in vollen Jahren seit Eintritt: unter 5, 5 bis unter
  10, 10 bis unter 25, 25 bis unter 40, 40 bis unter 50, 50 und mehr,
  Eintritt unbekannt.
- **Eintritte und Austritte** der letzten 10 Jahre (laufendes
  eingeschlossen): Eintritte nach Eintrittsdatum (bis heute) aller, auch
  inzwischen ehemaliger Mitglieder; Austritte nur erreichte (ein vermerkter
  Austritt in der Zukunft zählt erst ab seinem Tag).

**Reiter „Beiträge"** (`beitraege.verwalten`, nur Admin;
`App\Service\Statistik\Beitragsstatistik`), Jahr wählbar wie auf der Seite
„Beiträge". Grundlage wie 08 §2b: die erzeugten Jahresbeiträge des Jahres,
sonst die Hochrechnung. **Maßgeblich ist, ob für das Jahr ein Beitrag
besteht bzw. entstünde** – wer zum 1. Januar schon ausgetreten war, taucht
nicht auf; ein im Jahr ausgetretenes Mitglied mit erzeugtem Beitrag zählt
mit diesem Beitrag. Auswertungen: nach Beitragsart und nach Abteilung
(unverändert aus 08 §2b), nach Geschlecht, Altersgruppe (Alter am 1. Januar,
gleiche Gruppen), Wohnort (10 höchste Summen + Sonstige), aktiv/passiv,
Familie/Einzel und Zahlart. Für die Personen-Merkmale wird jeder Beitrag
zuerst gleichmäßig auf die Personen dahinter geteilt – das Mitglied und beim
Familien-Zahler die Familienmitglieder des Jahres ohne eigenen Beitrag
(`Beitragsverteilung::traeger()`) –, in Cent nach größtem Rest; jede
Auswertung ergibt so genau die Gesamtsumme. Die Zahlart ist die des Zahlers
und wird nicht geteilt. Merkmale sind die heutigen. Ein Beitrag zu einem
nicht mehr vorhandenen Mitglied (kommt praktisch nicht vor) steht als
„Mitglied unbekannt".

## Pflicht-Tests

Geburtstagsfenster Woche/Monat/Jahr über den Jahreswechsel, 29.02., runde
Alter; Ehrungsjahr-Berechnung inkl. unbekanntem Eintritt und „nur offene";
csv mit BOM, `;` und Feldern mit Semikolon/Anführungszeichen/Zeilenumbruch;
xlsx öffnet sich (gültiges ZIP, Inhalt per Rücklesen geprüft), Datum als
Datumszelle; BLSV-Export hat exakt die Spalten der Vorlage in der
Reihenfolge; Vorstand und Nur lesen sehen „Selbst kassieren" und die
Beitragsspalten, aber keine Knöpfe; jeder Export erzeugt einen Audit-Eintrag.
`/app/listen` liefert die Geburtstagsliste; Personen-Links zeigen auf die
Listenseite mit `mitglied=<id>`, Sortieren und Filter behalten die Person,
Speichern in der geöffneten Person bleibt auf der Liste, htmx tauscht nur
den Bereich `listen-bereich` – `ListenMitgliedTest`. Auffälligkeiten mit den
Karten-Hinweisen (#108): Filter je Art, Link öffnet den Reiter „Daten",
gefundene Anschriften erscheinen nicht – `KarteSeiteTest`.
Zu §7 (`MitgliederstatistikTest`, `BeitragsstatistikTest`,
`StatistikSeiteTest`, `tests/js/statistik.test.js`): Altersgrenzen
einschließlich (18 gehört zu 15–18, 17 ist unter 18), Alter am Stichtag;
Ehemalige zählen nicht, Austritte je Jahr nur erreichte, Eintritte auch
Ehemaliger; Top 10 + Sonstige; jede Beitragsauswertung ergibt die
Gesamtsumme auf den Cent, Familienbeitrag geteilt, Zahlart nicht geteilt;
alle Rollen sehen „Mitglieder", Vorstand und Nur lesen bekommen auf
„Beiträge" 403; die alte Adresse leitet weiter; keine Namen auf den Seiten.
