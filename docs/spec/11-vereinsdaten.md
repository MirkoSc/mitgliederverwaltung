# 11 – Vereinsdaten

Abschnitt „Verein" auf der Seite Einstellungen (06 §2), Admin und Verwaltung
(`admin.verein`, 02 §6); Export und Import (§5) nur der Admin.
Vereinsdaten sind öffentlich bekannte Angaben und liegen **unverschlüsselt**
in der Datenbank.

## 1. Felder

| Gruppe | Felder |
|---|---|
| Name | Vereinsname, Kurzname (für Kopfzeile und Dateinamen) |
| Anschrift | Straße, PLZ, Ort |
| Kontakt | Telefon, E-Mail, Website |
| Register | Registergericht, Vereinsregister-Nummer |
| Steuer | Finanzamt, Steuernummer, Datum des letzten Freistellungsbescheids (Gemeinnützigkeit) |
| Bank | Kontoinhaber, IBAN, BIC, Name der Bank, **Gläubiger-ID** |
| Lastschrift | Default-Mandatsdatum, Verwendungszweck-Vorlage (08 §4) |
| Verbände | Liste, beliebig viele Einträge: Verband, Vereinsnummer beim Verband, Bemerkung – z. B. BLSV, BFV (Fußball), Kegelverband |
| Logo | Bilddatei (§2) |

- Alle Felder optional; fehlen Gläubiger-ID oder IBAN, kann keine
  Lastschriftdatei erzeugt werden (08 §4) – die Seite Beiträge sagt das.
- Verbände: Zeilen hinzufügen, ändern, löschen, sortieren. Im Formular
  steht je Zeile eine **Position** (Reihenfolge) und zwei leere Zeilen zum
  Hinzufügen; eine Zeile ohne Verbandsname wird gelöscht (kein JavaScript
  nötig). Die
  BLSV-Vereinsnummer wird beim BLSV-Export angezeigt (09 §4), damit sie
  beim Hochladen zur Hand ist.
- IBAN wird normalisiert und per Prüfziffer geprüft (Hinweis, keine
  Sperre).

## 2. Logo

- Upload PNG oder JPEG (kein SVG – GD kann es nicht verarbeiten, und SVG
  kann Skript enthalten), max. 5 MB.
- Beim Upload leitet GD einmalig die Größen ab (alle als PNG, nie
  verzerrt): `kopf` (höchstens 96 × 96), `anmeldung` (höchstens 320 × 160),
  `favicon` (32 × 32), `apple` (180 × 180, weißer Grund), für die
  installierbare App (06 §6) `icon192` (192 × 192), `icon512` (512 × 512)
  und `maskable` (512 × 512, weißer Grund, Logo innerhalb der Schutzzone).
  Das Original wird nicht aufbewahrt; fehlen bei einem früher
  hochgeladenen Logo die App-Größen, entstehen sie beim ersten Abruf aus
  `anmeldung` (gröber – ein neuer Upload macht sie scharf). Bilder über 16 Megapixel werden abgelehnt. Ein
  abgelehnter Upload lässt das bisherige Logo unverändert.
- Ablage in `shared/var/logo/` (überlebt Updates, Teil des Backups),
  ausgeliefert über die **öffentliche** Route `GET /logo/<größe>` (Anmeldeseite
  und Favicon brauchen sie ohne Sitzung; ein Logo ist öffentliche
  Information) mit `?v=<Zeitstempel>` als Cache-Busting – mit `?v=` ein Jahr
  cachebar, ohne `no-cache`; ohne Logo der neutrale Platzhalter
  (`public/img/vereinslogo-platzhalter.svg`). „Logo entfernen" löscht Dateien
  und Einstellung.
- Angezeigt in der Kopfzeile jeder Seite, auf der Anmeldeseite und als
  Favicon.

## 3. Verwendung

| Wo | Was |
|---|---|
| Kopfzeile, Seitentitel, Mails | Kurzname bzw. Vereinsname, Logo |
| Lastschriftdatei | Kontoinhaber (bzw. Vereinsname), IBAN, BIC, Gläubiger-ID, Verwendungszweck-Vorlage |
| BLSV-Export | BLSV-Vereinsnummer als Hinweis über dem Export |

## 4. Datenmodell

| Tabelle | Spalten |
|---|---|
| `setting` | `verein` (JSON aller Felder aus §1 außer Verbänden und Logo) |
| `verband` | id, name, vereinsnummer, bemerkung, sortierung (Migration `050_verband.sql`) |
| `setting` | `logo_version` (Zeitstempel des Uploads) |

## 5. Export und Import

Für eine **Neuinstallation**: Der Admin lädt den Abschnitt Verein als eine
JSON-Datei herunter und spielt sie in einer anderen Installation wieder
ein. Die Datei muss nicht menschenlesbar sein.

- **Recht:** nur der Admin (`admin.system`, vorhandenes Recht – kein neues).
  Die Verwaltung bearbeitet die Vereinsdaten (`admin.verein`), sieht Export
  und Import aber nicht; beide Routen antworten ihr 403, der Knopf fehlt.
- **Umfang:** nur der Abschnitt Verein – alle Felder aus §1, die Verbände
  (in Reihenfolge) und das Logo. **Nicht** enthalten: Abteilungen,
  Beitragsarten, Funktionen, Geburtstage, Ehrungen, Mitglieder, Benutzer,
  Mail-/SMTP-Einstellungen, Schlüssel und andere Geheimnisse (dafür gibt es
  Backup/Restore, 04). Die Datei enthält die Bankverbindung des Vereins.
- **Export:** `GET /admin/verein/export`, Dateiname
  `vereinsdaten-<kurzname>-JJJJ-MM-TT.json` (Kurzname auf Kleinbuchstaben,
  Ziffern und Bindestriche bereinigt, sonst ohne). Wird beim Download
  erzeugt, nie gespeichert; `Cache-Control: no-store`. Audit-Log
  `verein.exportiert`.
- **Format** (kompaktes JSON, `version` 1):

  ```json
  {"format": "mitgliederverwaltung-vereinsdaten", "version": 1,
   "app_version": "0.7.0", "erzeugt": "2026-11-19T14:00:00+01:00",
   "verein": {"vereinsname": "…", "iban": "…"},
   "verbaende": [{"name": "BLSV", "vereinsnummer": "…", "bemerkung": "…"}],
   "logo": {"kopf": "<Base64-PNG>", "anmeldung": "…", "favicon": "…",
            "apple": "…", "icon192": "…", "icon512": "…", "maskable": "…"}}
  ```

  `logo` ist `null` ohne Logo. Änderungen am Format erhöhen `version`.
- **Import:** `POST /admin/verein/import` (CSRF), Datei `.json`, höchstens
  10 MB, dazu der Pflicht-Haken „Die vorhandenen Vereinsdaten werden
  vollständig ersetzt.“ Ohne Haken bleibt alles unverändert.
- **Ersetzen:** Alle Felder aus `verein` werden übernommen, **fehlende
  Felder werden leer**; die Verbände werden komplett durch die der Datei
  ersetzt (neue IDs); das Logo wird ersetzt, bei einer Datei ohne Logo
  entfernt. Unbekannte Schlüssel werden ignoriert. `logo_version` wird neu
  gesetzt, damit Kopfzeile und Favicon das neue Logo laden.
- **Prüfen vor dem Schreiben** – besteht die Datei nicht, bleibt alles
  unverändert (Fehlermeldung): gültiges JSON, richtige Kennung,
  Formatversion bekannt (neuere → „Installation erst aktualisieren“),
  Felder (auch die der Verbände) nur als Text, höchstens 100 Verbände.
  Felder und Verbände werden in **einer Transaktion** geschrieben.
- **Logo:** jede Größe muss sich per GD als PNG lesen lassen und innerhalb
  der Maße aus §2 liegen (die später ergänzten App-Größen `icon192`,
  `icon512`, `maskable` dürfen fehlen, sie entstehen beim ersten Abruf).
  Besteht das Logo nicht, wird es **nicht** übernommen (das bisherige
  bleibt), die übrigen Daten schon, mit Hinweis. Die Logodateien werden erst
  nach erfolgreicher Prüfung und nach dem Schreiben der Daten angelegt
  (über Temporärdateien, wie beim Upload).
- Dieselben Normalisierungen und **Hinweise statt Sperren** wie im
  Formular (§1: IBAN, BIC, Gläubiger-ID, Datum, E-Mail). Danach die Seite
  mit Erfolgsmeldung und Hinweisen. Audit-Log `verein.importiert`, ohne
  Inhalte.

## Pflicht-Tests

Speichern und Lesen aller Felder; Verbände hinzufügen/ändern/löschen;
IBAN-Normalisierung; Logo-Upload: PNG und JPEG werden abgeleitet, SVG
und zu große Dateien abgelehnt, Route liefert das Bild mit passendem
Content-Type; ohne Logo Platzhalter; Vorstand kann den Abschnitt nicht
öffnen und nicht speichern.

Export und Import (§5, `VereinsdatenAustauschTest`): Rundreise in eine leere
Installation ergibt dieselben Felder, Verbände (Reihenfolge) und
Logodateien; Import ersetzt (fehlender Verband weg, fehlendes Feld leer,
Datei ohne Logo entfernt es); ungültiges JSON, falsche Kennung, zu neue
Version, Nicht-Text-Felder, fehlender Haken, falsche Endung → nichts
geändert; kaputtes Logo → Daten ja, Logo nein, Hinweis; Verwaltung, Vorstand
und Nur lesen: 403 und kein Knopf; ohne CSRF-Token abgewiesen; die Datei
enthält keine Geheimnisse; Audit-Einträge ohne Inhalte.
