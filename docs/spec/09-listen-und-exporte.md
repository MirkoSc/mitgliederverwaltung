# 09 – Listen und Exporte

Quelle: `docs/fachlichkeit.md` §6–8. Alle Listen liegen auf **einer**
Seite „Listen" (06 §2) mit einer Auswahl oben, wenigen Filtern und einem
Export-Knopf. Ehemalige sind überall ausgeblendet, außer beim
Gesamtexport mit Häkchen „Ehemalige einschließen".

## 1. Gemeinsame Regeln

- Jede Liste: Tabelle am PC, Karten am Handy, Klick auf eine Zeile
  öffnet das Mitglied.
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
| **Geburtstage** | Name, Geburtstag, neues Alter, Abteilungen; runde Geburtstage markiert | Zeitraum: Woche / Monat / Jahr (mit Vor/Zurück), „nur runde" | beide |
| **Ehrungen** | Name, Eintrittsdatum, Jahre, Stufe, Abteilungen, geehrt am | Jahr, „nur offene" | beide |
| **Funktionen** | Name, Funktion, Lizenznummer, Abteilungen, Kontakt | Funktion | beide |
| **Selbst kassieren** | offene Jahresbeiträge mit Zahlart `selbst` oder ohne gültige IBAN: Name, Jahr, Betrag, Kontakt | Jahr | Admin |
| **Auffälligkeiten** | alle Hinweise aller Mitglieder (07 §6): Name, Hinweis | Art des Hinweises | beide |
| **BLSV-Meldung** | §4 | Stichtag | beide |
| **Alle Mitglieder** | Gesamtexport §5 | Ehemalige einschließen | beide (Beitragsspalten nur Admin) |

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
`beitraege.verwalten`, also Admin) gelten Beitragsart, Zahlart, IBAN, BIC,
Kontoinhaber, Mandatsreferenz, Mandatsdatum sowie Betrag und Status des
Jahresbeitrags des laufenden Jahres; der Vorstand bekommt alle übrigen Felder.

## 6. Datenmodell

| Tabelle | Spalten |
|---|---|
| `ehrung` | id, mitglied_id, stufe_jahre, geehrt_am, vermerkt_von |
| `setting` | `runde_geburtstage`, `ehrungsstufen` (JSON-Listen) |

## Pflicht-Tests

Geburtstagsfenster Woche/Monat/Jahr über den Jahreswechsel, 29.02., runde
Alter; Ehrungsjahr-Berechnung inkl. unbekanntem Eintritt und „nur offene";
csv mit BOM, `;` und Feldern mit Semikolon/Anführungszeichen/Zeilenumbruch;
xlsx öffnet sich (gültiges ZIP, Inhalt per Rücklesen geprüft), Datum als
Datumszelle; BLSV-Export hat exakt die Spalten der Vorlage in der
Reihenfolge; Vorstand sieht „Selbst kassieren" nicht und bekommt keine
Beitragsspalten; jeder Export erzeugt einen Audit-Eintrag.
