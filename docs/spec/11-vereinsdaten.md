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
  `favicon` (32 × 32), `apple` (180 × 180, weißer Grund). Das Original wird
  nicht aufbewahrt. Bilder über 16 Megapixel werden abgelehnt. Ein
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

## Pflicht-Tests

Speichern und Lesen aller Felder; Verbände hinzufügen/ändern/löschen;
IBAN-Normalisierung; Logo-Upload: PNG und JPEG werden abgeleitet, SVG
und zu große Dateien abgelehnt, Route liefert das Bild mit passendem
Content-Type; ohne Logo Platzhalter; Vorstand kann den Abschnitt nicht
öffnen und nicht speichern.
