# Hilfe-Bilder aufnehmen

Erzeugt die Bilder der Hilfe-Anleitungen (`docs/spec/12-hilfe.md` §6) aus der
**lokalen** Docker-Umgebung. Gehört nicht zum Release.

## Ein Befehl für alles

```bash
tools/hilfe-screenshots/start.sh                  # alle Anleitungen, beide Modi, Bilder schreiben
tools/hilfe-screenshots/start.sh --pruefen        # nur prüfen, keine Bilder schreiben
tools/hilfe-screenshots/start.sh --nur anmelden   # nur eine Anleitung (auch mit --pruefen)
tools/hilfe-screenshots/start.sh --projekt issue-120 --pruefen
```

`start.sh` startet ein **eigenes** Docker-Projekt (Standard `hilfe`,
`--projekt <name>`) mit freiem Host-Port statt 8080, migriert, legt die
Beispieldaten an, nimmt erst im Modus normal, dann – nach erneutem Anlegen
der Daten – im Barrierefrei-Modus auf und räumt das Projekt ab (`--behalten`
lässt es stehen). Zwei Läufe liefern dasselbe Ergebnis, weil die Daten jedes
Mal neu angelegt werden.

Die Bilder landen in `public/img/hilfe/<slug>/<NN>-normal.png` und
`…-barrierefrei.png`. **Bilder werden nur im Abschluss-Issue (#119) bzw. nach
Oberflächen-Änderungen neu erzeugt und committet, nicht in jedem PR.**
Anleitungs-Issues nutzen `--pruefen`.

## Voraussetzungen

- Docker (Images von `mirror.gcr.io`, siehe `docs/spec/05-entwicklung.md`).
- `vendor/` im Repository (`docker compose run --rm app composer install`).
- Node 22 und Playwright mit Chromium, **nichts davon im Repository**:
  `npm install -g playwright && npx playwright install chromium`
  (in der Agenten-Umgebung bereits installiert).

## Was `--pruefen` prüft

Alle Abläufe laufen gegen die Anwendung, es werden keine Bilder geschrieben.
Das Skript scheitert (Ausgabe `FEHLER`, Exit-Code 1) bei

- einem Element, das nicht gefunden wird (kurze Wartezeit von 5 s),
- einer Seite mit HTTP-Status 400 oder höher,
- einem Fehler in der Browser-Konsole oder einem Skriptfehler,
- fehlendem Abgleich: die Nummern aus `foto()` müssen genau die Schritte mit
  `bild` sein (`tools/hilfe-anleitungen.php`), jede Anleitung mit Bildern
  braucht eine Aufnahme-Datei und jede Aufnahme-Datei eine Anleitung.

## Neue Anleitung: ihre Aufnahme

Zu `app/data/hilfe/NN-<slug>.php` gehört `anleitungen/<slug>.mjs`:

```js
export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await markiere(seite.getByRole('link', { name: 'Neues Mitglied' }));
    await foto(1);                       // Bild zu Schritt 1
    // …
}
```

- Läuft **angemeldet** als Demo-Zugang des jeweiligen Modus (Rolle Vorstand,
  Authenticator-App; Werte in `konten.json`). `seite` ist eine Playwright-Seite
  mit `baseURL`; Adressen sind Pfade.
- `markiere(element)` blättert zum Element und setzt einen kräftigen Rahmen,
  der nur im Browser des Skripts existiert. Er verschwindet nach `foto()`.
- `foto(n)` speichert Schritt `n` (ab 1) im aktuellen Modus. Mehrfaches `foto(n)`
  für denselben Schritt ist ein Fehler.
- Elemente über **Rolle und deutsche Beschriftung** finden (`getByRole`,
  `getByLabel`), Mitglieder über ihre Nummer (`getByRole('row', { name: /\b1001\b/ })`).
  Dann fällt eine geänderte Oberfläche sofort auf. Die Locator sind streng:
  passt mehr als ein Element, scheitert die Aufnahme.
- Die Daten werden nur zwischen den Modi neu angelegt. Eine Aufnahme, die etwas
  ändert (Mitglied anlegen, Modus des Kontos umschalten), stellt den Ausgangszustand
  am Ende wieder her, wenn spätere Anleitungen ihn brauchen.
- Anderer Zugang: `export const zugang = 'admin';` sucht in `konten.json` die
  Einträge `admin-normal` und `admin-barrierefrei` (dort ergänzen; das
  Beispieldaten-Skript legt jeden Eintrag an).
- Nur eine Probe (kein Bezug zu einer Anleitung): `export const probe = true;`.
  Ihre Bilder gehen nach `probe-ausgabe/` (nicht in git), `beispiel.mjs` ist so eine.

Bilder: PNG, 1280 × 800, `de-DE`, `Europe/Berlin`, helles Farbschema, keine
Animationen, höchstens 250 KB je Bild und 10 MB zusammen.

## Beispieldaten und Demo-Zugänge

`tools/hilfe-beispieldaten.php` (läuft im App-Container, nur mit `debug => true`)
löscht **alle** Mitglieder und Familien und legt etwa 30 erfundene Mitglieder,
fünf Familien und die Vereinsdaten „SV Musterstadt e. V." neu an, dazu die Zugänge
aus `konten.json` (`hilfe-normal@example.org`, `hilfe-barrierefrei@example.org`).
Das Passwort und die Geheimnisse der Authenticator-App stehen in `konten.json`
(erfundene Werte, nur für die lokale Umgebung). Am Login der Anwendung ändert sich
nichts: `lib/totp.js` rechnet den App-Code selbst aus.

## Tests

`node --test 'tests/js/**/*.test.js'` (reine Logik: App-Code gegen die Vektoren aus
RFC 6238, Bildnamen, Abgleich, Kommandozeile) und
`vendor/bin/phpunit tests/Tools` (Beispieldaten, Weigerung ohne `debug`).
