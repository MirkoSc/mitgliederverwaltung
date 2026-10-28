# Vendored JavaScript-Bibliotheken

Kein Build-Schritt und kein CDN: Die CSP erlaubt nur `script-src 'self'`
(CLAUDE.md §4), und ein CDN würde dem Anbieter jeden Aufruf einer Seite
melden, auf der Mitgliederdaten stehen. Jede Bibliothek liegt deshalb
unverändert hier und wird mit dem Release-ZIP ausgeliefert.

`tests/View/VendorAssetsTest.php` prüft, dass die Dateien unverändert sind –
die Prüfsummen unten sind der Sollwert.

## htmx

| | |
|---|---|
| Version | 2.0.10 |
| Datei | `htmx.min.js` |
| SHA-256 | `71ea67185bfa8c98c39d31717c6fce5d852370fcdfd129db4543774d3145c0de` |
| Quelle | `https://registry.npmjs.org/htmx.org/-/htmx.org-2.0.10.tgz`, Datei `package/dist/htmx.min.js` |
| Projekt | <https://htmx.org/> · <https://github.com/bigskysoftware/htmx> |
| Lizenz | 0BSD (BSD Zero Clause) – GPL-verträglich |

Übernommen aus Vereinsbelege (dort geprüft und byte-gleich mit unpkg und
jsDelivr). Hier erneut geprüft: der npm-Tarball stimmt mit dem Shasum der
Registry überein (`62442b0e2952a885ae2e50a7654b8b20d0981134`), die Datei
darin hat die Prüfsumme oben. Reines JavaScript ohne Abhängigkeiten, keine
externe URL und keine `sourceMappingURL` in der Datei, aktiv gepflegt. Das
einzige `eval` steckt in htmx' interner Hilfe für `hx-on`/`js:`-Ausdrücke –
die nutzt die Anwendung nicht (kein `hx-on`, CLAUDE.md §4), und die CSP ohne
`unsafe-eval` würde sie ohnehin blockieren.

Konfiguriert wird htmx über `<meta name="htmx-config">` im Layout, nicht über
ein Skript: `includeIndicatorStyles: false` (die Indikator-Styles stehen
handgeschrieben im Stylesheet) und `selfRequestsOnly: true`.
