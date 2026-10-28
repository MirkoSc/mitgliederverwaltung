# 11 – Vereinsdaten

Abschnitt „Verein" auf der Seite Einstellungen (06 §2), nur Admin.
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
- Verbände: Zeilen hinzufügen, ändern, löschen, sortieren. Die
  BLSV-Vereinsnummer wird beim BLSV-Export angezeigt (09 §4), damit sie
  beim Hochladen zur Hand ist.
- IBAN wird normalisiert und per Prüfziffer geprüft (Hinweis, keine
  Sperre).

## 2. Logo

- Upload PNG oder JPEG (kein SVG – GD kann es nicht verarbeiten, und SVG
  kann Skript enthalten), max. 5 MB.
- Beim Upload leitet GD einmalig die Größen ab: Kopfzeile, Anmeldeseite,
  Favicon (32 px), Apple-Touch-Icon (180 px). Muster wie das Vereinswappen
  im Vereinskalender.
- Ablage in `shared/var/logo/` (überlebt Updates, Teil des Backups),
  ausgeliefert über eine eigene Route mit `?v=<Zeitstempel>` als
  Cache-Busting; ohne Logo ein neutraler Platzhalter.
- Angezeigt in der Kopfzeile jeder Seite, auf der Anmeldeseite und als
  Favicon.

## 3. Verwendung

| Wo | Was |
|---|---|
| Kopfzeile, Seitentitel, Mails | Kurzname bzw. Vereinsname, Logo |
| Lastschriftdatei | Kontoinhaber (bzw. Vereinsname), IBAN, BIC, Gläubiger-ID, Verwendungszweck-Vorlage |
| BLSV-Export | BLSV-Vereinsnummer als Hinweis über dem Export |

## 4. Datenmodell

| Tabelle | Spalten | Verschl. |
|---|---|---|
| `setting` | `verein` (JSON aller Felder aus §1 außer Verbänden und Logo) | – |
| `verband` | id, name, vereinsnummer, bemerkung, sortierung | – |
| `setting` | `logo_version` (Zeitstempel des Uploads) | – |

## Pflicht-Tests

Speichern und Lesen aller Felder; Verbände hinzufügen/ändern/löschen;
IBAN-Normalisierung; Logo-Upload: PNG und JPEG werden abgeleitet, SVG
und zu große Dateien abgelehnt, Route liefert das Bild mit passendem
Content-Type; ohne Logo Platzhalter; Vorstand kann den Abschnitt nicht
öffnen und nicht speichern.
