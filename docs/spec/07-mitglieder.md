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
- Beitrag: der **Zahler** hat die Beitragsart „Familie"; Elternteile und
  Kinder haben normalerweise **keine** eigene Beitragsart (08 §1).

## 4. Funktionen

Einstellungen: Liste der Funktionen (Name, Sortierung, aktiv, „mit
Lizenznummer" ja/nein). Startbestand: Übungsleiter (mit Lizenznummer),
Wirtschaftshelfer, Bahndienst, Jugendtrainer. Am Mitglied als Häkchen.

## 5. Nachweise

Je Mitglied 0..n Nachweise: Art (`rente`/`studium`/`ausbildung`/
`sonstiges`), gültig bis (optional), Bemerkung, optional **eine Datei**
(Bild oder PDF, verschlüsselter Blob, 01/03), **vermerkt von** (Benutzer)
und **am** – automatisch gesetzt, nicht änderbar. Nachweise werden nicht
versioniert (sie sind selbst ein Verlauf); löschen nur Admin.

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

## 7. Versionen

- Jedes Speichern eines Mitglieds, bei dem sich etwas ändert, erzeugt eine
  **Version**: vollständiger Stand aller Felder aus §1 (einschließlich
  Abteilungen, Funktionen, Beitragsart, Familie und Rolle), dazu wer, wann
  und ein Anlass (`angelegt`, `geaendert`, `importiert`,
  `wiederhergestellt aus Version n`).
- Reiter **Verlauf**: Versionen absteigend; je Version die geänderten
  Felder als „alt → neu".
- **Zurückholen**: übernimmt die Werte der gewählten Version als **neue**
  Version hinten. Gibt es die Familie nicht mehr, bleibt die Familie leer
  (Hinweis); dasselbe für gelöschte Abteilungen/Funktionen.
- Nicht versioniert: Jahresbeiträge/Einzüge (08), Nachweise (§5),
  Ehrungs-Vermerke (09).

## 8. Datenmodell

Verschlüsselung nach 03; **ein Datenschlüssel je Mitglied** (statt je
Zeile): `mitglied.dek_sealed` verschlüsselt auch Versionen und Nachweise
dieses Mitglieds, jeweils mit eigener AAD (`tabelle|id|spalte`). Das hält
das Entschlüsseln einer Liste bei ~700 Mitgliedern bei einem
`box_seal_open` je Mitglied.

Klartext bleibt nur, was SQL zum Filtern, Zählen und Verknüpfen braucht
und für einen DB-Leser ohne Namen wenig verrät (E-F5):

| Tabelle | Spalten | Verschl. |
|---|---|---|
| `mitglied` | id, mitgliedsnummer UNIQUE, beitragsart_id NULL, familie_id NULL, familie_rolle NULL, dek_sealed, daten_enc (JSON aller übrigen Felder aus §1), version (int), created_at, updated_at | T (daten) |
| `mitglied_abteilung` | mitglied_id, abteilung_id | – |
| `mitglied_funktion` | mitglied_id, funktion_id | – |
| `mitglied_version` | id, mitglied_id, nr, anlass, geaendert_von (user_id), geaendert_am, daten_enc (vollständiger Stand inkl. der Klartextspalten und Verknüpfungen) | T |
| `familie` | id, name_enc, dek_sealed, created_at | T (name) |
| `nachweis` | id, mitglied_id, art, daten_enc (gültig bis, Bemerkung), blob_id NULL, vermerkt_von, vermerkt_am | T |
| `abteilung` | id, name, blsv_nummer, sortierung, aktiv | – |
| `funktion` | id, name, mit_lizenz, sortierung, aktiv | – |

- Klartext sind nur Nummer und Verknüpfungen (Fremdschlüssel halten die
  Daten stimmig). Alles andere – auch aktiv/passiv, Zahlart, Ein- und
  Austritt – liegt im JSON; Listen und Filter rechnen PHP nach dem
  Entschlüsseln.
- **Suche** ohne Blind Index: die Mitgliederliste wird in der Session
  entschlüsselt und in PHP gefiltert (Name, Mitgliedsnummer, Ort,
  E-Mail). Bei ~700 Mitgliedern genügt das; wird es zu langsam, hilft ein
  verschlüsselter Session-Index wie in Vereinsbelege.

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
eindeutig, nächste freie Nummer. IBAN-Prüfziffer. Verschlüsselung: kein
Name, keine Anschrift, kein Geburtsdatum, keine IBAN im Klartext in der DB
(Test liest die Rohzeilen). Rechte-Matrix §9 serverseitig.
