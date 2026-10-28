# Schrift

Eingebunden in `public/css/app.css` per `@font-face`, ausgeliefert vom
eigenen Server: Die CSP erlaubt nur `font-src 'self'` (CLAUDE.md §4), und ein
Schriften-Dienst würde dem Anbieter jeden Aufruf einer Seite melden, auf der
Mitgliederdaten stehen. Die Dateien liegen unter `css/`, damit sie auch im
Wartungsmodus erreichbar sind (der Shim lässt `/css/` durch).

`tests/View/SchriftTest.php` prüft, dass die Dateien unverändert sind – die
Prüfsummen unten sind der Sollwert.

## Atkinson Hyperlegible Next

| | |
|---|---|
| Version | 5.3.0 (Fontsource-Paket `@fontsource-variable/atkinson-hyperlegible-next`) |
| Dateien | `atkinson-hyperlegible-next-latin-wght-normal.woff2` (Latein, Gewichte 200–800) |
| SHA-256 | `18b2a1a39a2fa298b0ba5390aca68462669826c90925656f1c1f6796e0e1bbaf` |
| Dateien | `atkinson-hyperlegible-next-latin-ext-wght-normal.woff2` (erweitertes Latein, z. B. Ł, Ş, ř) |
| SHA-256 | `7dae0c6c66af1aec82e096186aeb1f0e6fa36ab8061ed65422f2b7daf4dd93f3` |
| Quelle | `https://registry.npmjs.org/@fontsource-variable/atkinson-hyperlegible-next/-/atkinson-hyperlegible-next-5.3.0.tgz`, Ordner `package/files/` |
| Projekt | <https://github.com/googlefonts/atkinson-hyperlegible-next> (Braille Institute) |
| Lizenz | SIL Open Font License 1.1 – `OFL.txt` (Lizenztext aus dem Paket); mit GPL-Software frei nutzbar und weitergebbar |

Geprüft: Der Tarball stimmt mit dem Shasum der Registry überein
(`defa6480f1e9e0591dc398e0ab66dcb41e754133`), die Dateien darin haben die
Prüfsummen oben. Reine Schriftdateien ohne Abhängigkeiten, aktiv gepflegt
(Fontsource-Release Juli 2026). Kursiv wird nicht mitgeliefert – die
Oberfläche verwendet keine Kursivschrift.

Warum diese Schrift: Sie wurde vom Braille Institute für Menschen mit
Sehschwäche gezeichnet. Leicht verwechselbare Zeichen (I l 1, 0 O, rn m)
sind klar unterschieden – wichtig bei Namen und Mitgliedsnummern, und sie
trägt den Barrierefrei-Modus ohne zweite Schrift
(`docs/spec/06-oberflaeche.md` §4). Fehlt die Datei, greift die Systemschrift.
