# Arbeitsplan

37 Issues, geschnitten für **eine Claude-Code-Sitzung bzw. einen
Automatik-Job je Issue**. Die Reihenfolge ergibt sich allein aus
„Abhängig von" im Issue; alles in derselben Welle kann **parallel** laufen.
Die Automatik (`.github/workflows/`, siehe `docs/VORGEHEN.md`) startet ein
Issue, sobald alle Abhängigkeiten geschlossen sind.

## Modelle

| Modell | Wofür | Issues |
|---|---|---|
| **Opus 5.5** | Fundament, Sicherheit, Geld, komplexe Abläufe, Gestaltung, jedes Review | #1, #2, #3, #6, #8, #9, #12, #15, #19, #29, #30, #34, #36, #37 |
| **Sonnet 5.5** | übernehmbarer Code, normale Features | #4, #5, #7, #10, #11, #13, #16–#18, #20–#26, #28, #32, #33 |
| **Haiku 5.5** | kleine, klar umrissene Aufgaben | #14, #27, #31, #35 |

Fable 5.1 wird nicht verwendet. Das Review vor jedem Merge macht immer
Opus 5.5. **Effort** (low/medium/high/xhigh) steht als Label am Issue und
gibt vor, wie gründlich gearbeitet und getestet wird.

## Reihenfolge und Parallelität

| Issue | Titel | Modell | Effort | Abhängig von | Migrationen |
|---|---|---|---|---|---|
| **Welle 0** | | | | | |
| #1 | Projektgerüst, CI und Erweiterungspunkte | Opus 5.5 | xhigh | – | 001–009 |
| **Welle 1** | | | | | |
| #2 | Release-Workflow, Signatur, Shim, ReleaseDownloader | Opus 5.5 | high | #1 | – |
| #5 | Server-Schlüssel und verschlüsselte Dateien (Blobs) | Sonnet 5.5 | high | #1 | 010–014 |
| #7 | Mail und Cron | Sonnet 5.5 | medium | #1 | 020–024 |
| #9 | Designsystem und Layout | Opus 5.5 | high | #1 | – |
| #11 | Audit-Log | Sonnet 5.5 | medium | #1 | 030–034 |
| #14 | Rechtstexte Impressum und Datenschutz | Haiku 5.5 | medium | #1 | – |
| #15 | Datenmodell Mitglieder, Beiträge, Ehrungen + Seeds | Opus 5.5 | high | #1 | 040–049 |
| #33 | Umwandlungsskript für die Altdaten | Sonnet 5.5 | high | #1 | – |
| **Welle 2** | | | | | |
| #6 | Benutzer, Anmeldung und Rollen | Opus 5.5 | high | #1, #7 | 015–019 |
| #12 | Backup und Restore (verschlüsselt) | Opus 5.5 | high | #5 | – |
| #13 | Systemcheck und Integritätscheck | Sonnet 5.5 | medium | #2 | – |
| #16 | Vereinsdaten und Logo | Sonnet 5.5 | medium | #1, #9 | 050–054 |
| #17 | Einstellungen: Abteilungen, Funktionen, Beitragsarten, Geburtstage, Ehrungsstufen | Sonnet 5.5 | medium | #9, #15 | – |
| #18 | Mitglieder-Seite: Liste, Suche, Filter | Sonnet 5.5 | high | #9, #15 | – |
| #19 | Mitglied anlegen und bearbeiten (Reiter Daten) | Opus 5.5 | high | #9, #15 | – |
| #24 | Listen-Seite und Exporte (CSV, XLSX) | Sonnet 5.5 | high | #9, #11, #15 | – |
| **Welle 3** | | | | | |
| #3 | Installer und setup.php | Opus 5.5 | high | #2, #5, #6, #7, #12 | – |
| #4 | Self-Updater und Wartungsmodus | Sonnet 5.5 | high | #2, #12 | – |
| #8 | Zweiter Faktor: E-Mail-Code (Standard), App, Backup-Codes | Opus 5.5 | high | #6, #5 | 025–029 |
| #10 | Barrierefrei-Modus: Umschalter und Leerlauf-Warnung | Sonnet 5.5 | low | #6, #9 | – |
| #20 | Familien | Sonnet 5.5 | medium | #19 | – |
| #21 | Hinweise (Regeln als Hinweise, nie Sperren) | Sonnet 5.5 | high | #19 | – |
| #22 | Verlauf: Versionen ansehen und zurückholen | Sonnet 5.5 | medium | #19 | – |
| #23 | Nachweise mit optionaler Datei | Sonnet 5.5 | medium | #19, #5 | – |
| #25 | Liste Geburtstage | Sonnet 5.5 | medium | #24 | – |
| #26 | Liste Ehrungen mit Vermerk „geehrt“ | Sonnet 5.5 | medium | #24 | – |
| #29 | Jahresbeiträge und Reiter Beiträge | Opus 5.5 | high | #9, #15, #19 | 060–064 |
| **Welle 4** | | | | | |
| #27 | Listen Funktionen und Auffälligkeiten | Haiku 5.5 | medium | #24, #21 | – |
| #28 | Gesamtexport und BLSV-Meldung | Sonnet 5.5 | medium | #16, #20, #24 | – |
| #30 | Einzug und Lastschriftdatei pain.008 mit Archiv | Opus 5.5 | xhigh | #5, #16, #29 | 065–069 |
| #31 | Liste „Selbst kassieren“ | Haiku 5.5 | low | #24, #29 | – |
| #32 | Erstimport mit Vorschau | Sonnet 5.5 | high | #9, #11, #20 | – |
| **Welle 5** | | | | | |
| #34 | Abgleich-Import mit Merge-Ansicht | Opus 5.5 | high | #22, #32 | – |
| #35 | Betriebshandbuch und Datenschutz-Vorlage | Haiku 5.5 | medium | #3, #4, #12, #30, #32 | – |
| **Welle 6** | | | | | |
| #36 | Gesamtdurchlauf und Integrationsfehler beheben | Opus 5.5 | high | #3, #4, #8, #10, #13, #14, #17, #18, #23, #25, #26, #27, #28, #30, #31, #33, #34 | – |
| **Welle 7** | | | | | |
| #37 | Security-Review vor der ersten Installation *(manuell)* | Opus 5.5 | high | #36 | – |


Kritischer Pfad: #1 → #15 → #19 → #29 → #30 → #35/#36. Bis zu 9 Issues
laufen gleichzeitig (Wellen 1–3).

## Regeln für parallele Arbeit

Stehen in `docs/spec/05-entwicklung.md` §6: Erweiterungspunkte statt
zentraler Dateien, feste Migrationsnummern je Issue (Spalte oben; ein Issue
ohne Bereich nimmt bei Bedarf `1NN` mit NN = Issue-Nummer), isolierte
Docker-Projekte für Tests, Konflikte beim Merge löst der jeweilige Job.

## Labels

| Label | Bedeutung |
|---|---|
| `auto` | wird von der Automatik bearbeitet |
| `manuell` | nur von Hand starten (#37) |
| `in-arbeit` | ein Job arbeitet gerade daran |
| `braucht-hilfe` | Automatik ist hängen geblieben – Mirko schaut drauf, entfernt das Label, dann geht es weiter |
| `modell:opus` / `modell:sonnet` / `modell:haiku` | Modell für Umsetzung und Reparatur |
| `effort:low` … `effort:xhigh` | Gründlichkeit |
| `welle:N` | nur zur Übersicht |
