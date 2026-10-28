# 08 – Beiträge und Einzug

Quelle: `docs/fachlichkeit.md` §5. Alles in dieser Datei ist **nur für
den Admin** (Rolle Vorstand: kein Zugriff, serverseitig geprüft).

## 1. Beitragsarten

Einstellungen: Name, Betrag (Cent), **Regel** (für Hinweise, 07 §6),
**Aktiv-Art** (passt zu aktiv / passiv / egal), Sortierung, aktiv.
Startbestand (Migration-Seed, danach frei änderbar):

| Name | Betrag | Regel | Aktiv-Art |
|---|---:|---|---|
| Aktiv | 85 € | – | aktiv |
| Passiv | 50 € | – | passiv |
| Jugend/Schüler/Studenten/Azubis | 45 € | `jugend` | egal |
| Familie | 130 € | `familie` | egal |
| Rentner aktiv | 50 € | `rentner` | aktiv |
| Rentner passiv | 30 € | `rentner` | passiv |
| Mindestbeitrag | 25 € | – | egal |
| Beitragsfrei | 0 € | `beitragsfrei` | egal |

- Jedes Mitglied hat **höchstens eine** Beitragsart, unabhängig von der
  Zahl seiner Abteilungen. Familienmitglieder außer dem Zahler haben
  keine.
- Ein geänderter Betrag wirkt nur auf Jahresbeiträge, die **danach**
  erzeugt werden; erzeugte Jahresbeiträge behalten ihren Betrag. Damit
  gilt „ab dem nächsten Beitragsjahr" ohne Gültigkeitsdaten.
- Löschen nur ohne zugeordnete Mitglieder, sonst deaktivieren.

## 2. Jahresbeiträge

Ein **Jahresbeitrag** je Mitglied und Jahr: Betrag, Status, Vermerke.

- **Erzeugen**: Knopf „Beiträge für <Jahr> erzeugen" (Seite Beiträge).
  Legt für jedes nicht ehemalige Mitglied mit Beitragsart und Betrag > 0
  den fehlenden Jahresbeitrag an. **Mehrfach drückbar** – es kommen nur
  fehlende dazu (eindeutig je Mitglied und Jahr). So werden Neueintritte
  im Laufe des Jahres einfach durch erneutes Drücken nachgezogen.
- **Betrag**: Betrag der Beitragsart. Eintritt im selben Jahr ⇒
  **anteiliger Vorschlag** nach angefangenen Monaten:
  `betrag × (13 − Eintrittsmonat) / 12`, kaufmännisch auf Cent gerundet
  (Eintritt im Mai → 8/12). Eintritt vor dem Jahr oder unbekannt ⇒ voller
  Betrag – auch wenn der Einzug erst im März läuft.
- Solange der Status `offen` ist, ist der Betrag **von Hand änderbar**
  (Vermerk wer/wann).
- Status: `offen` → `im_einzug` (in einem Einzug) → `bezahlt`.
  - **Bezahlt von Hand**: Zahlweise (`bar`/`ueberweisung`/`sonstiges`),
    Datum, Vermerk.
  - **Rücklastschrift**: `bezahlt` → `offen`, Pflicht-Vermerk; der
    Beitrag kann erneut eingezogen oder von Hand bezahlt werden.
- Vermerke bilden eine Liste (wer, wann, was) im verschlüsselten Teil.
- Reiter „Beiträge" am Mitglied: alle Jahre mit Status und Vermerken.

## 3. Einzug

Ablauf auf **einer** Seite (06 §2), von oben nach unten:

1. **Zusammenstellen**: Liste der offenen Jahresbeiträge mit Zahlart
   `lastschrift` und gültiger IBAN, alle vorausgewählt, abwählbar.
   Daneben, nicht auswählbar: offene Beiträge ohne gültige IBAN bzw. mit
   Zahlart `selbst` (Verweis auf Liste „Selbst kassieren", 09).
   Fälligkeitsdatum wählen (Vorschlag: nächster Bankarbeitstag + 1).
2. **Erzeugen**: legt den Einzug an, alle gewählten Beiträge gehen auf
   `im_einzug`. Der Einzug speichert eine **eigene Kopie** aller Angaben
   (Name, IBAN, Betrag, Mandat, Verwendungszweck), damit die Datei
   später unverändert erneut heruntergeladen werden kann.
3. **Datei herunterladen** (beliebig oft) und im Online-Banking der
   Sparkasse hochladen.
4. **Bestätigen**, wenn die Bank eingezogen hat ⇒ alle Beiträge des
   Einzugs `bezahlt` (Datum = Fälligkeitsdatum).
   Oder **Verwerfen** (Datei wurde nicht eingereicht) ⇒ alle zurück auf
   `offen`.

Ein Einzug hat den Status `erzeugt`, `bestaetigt` oder `verworfen`; ein
bestätigter Einzug ist abgeschlossen (Rücklastschriften laufen je Beitrag,
§2). Liste der bisherigen Einzüge unten auf der Seite.

## 4. Lastschriftdatei

**SEPA-Basislastschrift pain.008.001.08** nach DFÜ-Abkommen Anlage 3,
Version 3.7 (Sparkasse Aschaffenburg Miltenberg). Vorlage für den Code:
pain.001 in Vereinsbelege (`App\Service\Bank\Sepa`).

- Eine Datei = ein Einzug = eine `PmtInf` (Lokalinstrument `CORE`,
  Sequenz `RCUR` für alle – seit 2016 ist `FRST` nicht mehr nötig;
  Sammelbuchung `BtchBookg=true`), je Beitrag eine Transaktion.
- Gläubiger: Vereinsname, Vereins-IBAN, BIC optional (sonst
  `NOTPROVIDED`), **Gläubiger-ID** – alles aus den Einstellungen.
- Je Transaktion: `EndToEndId` (Einzug + Mitgliedsnummer + Jahr),
  Betrag, Mandatsreferenz, Mandatsdatum, Name des Kontoinhabers (falls
  abweichend, sonst des Mitglieds), IBAN, BIC optional, Verwendungszweck
  aus einer Vorlage in den Einstellungen (Default „Mitgliedsbeitrag
  {jahr} Nr. {mitgliedsnummer} {name}", max. 140 Zeichen).
- Zeichensatz: Umlaute und ß werden umschrieben (ä → ae …), andere
  Zeichen außerhalb des SEPA-Zeichensatzes ersetzt – so nimmt jede Bank
  die Datei an.
- Fehlen Gläubiger-ID oder Vereins-IBAN, ist „Erzeugen" gesperrt – die
  einzige echte Sperre, weil die Datei sonst wertlos ist; der Hinweis
  sagt, wo es einzutragen ist.
- Dateiname: `lastschrift-<jahr>-<einzug-id>.xml` (keine Namen).

## 5. Einstellungen (Abschnitt „Verein")

Vereinsname, Vereins-IBAN, Vereins-BIC (optional), Gläubiger-ID,
Default-Mandatsdatum, Verwendungszweck-Vorlage.

## 6. Datenmodell

| Tabelle | Spalten | Verschl. |
|---|---|---|
| `beitragsart` | id, name, betrag_cent, regel, aktiv_art, sortierung, aktiv | – (Beitragsordnung ist vereinsöffentlich) |
| `jahresbeitrag` | id, mitglied_id, jahr, betrag_cent, status, einzug_id NULL, daten_enc (Vermerke, Zahlweise, Bezahlt-Datum) – UNIQUE(mitglied_id, jahr) | T (Schlüssel des Mitglieds, 07 §8) |
| `einzug` | id, status, faellig_am, erstellt_von, erstellt_am, bestaetigt_am NULL, anzahl, summe_cent, dek_sealed, daten_enc (Kopie aller Transaktionsangaben, MsgId) | T |

Betrag und Status bleiben Klartext (E-F5): ohne Namen verraten sie nur
die Beitragsart, und Summen je Status lassen sich in SQL zählen.

## Pflicht-Tests

Anteilsberechnung (Januar = voll, Mai = 8/12, Dezember = 1/12, Rundung);
Erzeugen ist idempotent und zieht Neueintritte nach; ehemalige und
beitragsfreie Mitglieder bekommen keinen Beitrag; Statusübergänge inkl.
Verwerfen und Rücklastschrift; Betrag nur im Status `offen` änderbar;
Lastschriftdatei gegen das XSD `pain.008.001.08` (als Fixture
eingecheckt) mit und ohne BIC, mit abweichendem Kontoinhaber, mit
Umlauten; Kontrollsumme und Anzahl stimmen; erneuter Download liefert
identische Datei; Vorstand bekommt auf jeder Route dieser Datei 403.
