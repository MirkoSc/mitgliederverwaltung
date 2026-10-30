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
handgeschrieben im Stylesheet), `selfRequestsOnly: true` und
`historyCacheSize: 0` (keine Seitenkopien mit Mitgliederdaten im
`sessionStorage`, 02 §7) und `allowEval: false`.

## Leaflet

| | |
|---|---|
| Version | 1.9.4 |
| Ordner | `leaflet/` |
| Quelle | `https://registry.npmjs.org/leaflet/-/leaflet-1.9.4.tgz`, Ordner `package/dist/` |
| Projekt | <https://leafletjs.com/> · <https://github.com/Leaflet/Leaflet> |
| Lizenz | BSD-2-Clause (`leaflet/LICENSE`) – GPL-verträglich |

| Datei | SHA-256 |
|---|---|
| `leaflet/leaflet.js` | `db49d009c841f5ca34a888c96511ae936fd9f5533e90d8b2c4d57596f4e5641a` |
| `leaflet/leaflet.css` | `a7837102824184820dfa198d1ebcd109ff6d0ff9a2672a074b9a1b4d147d04c6` |
| `leaflet/images/layers.png` | `1dbbe9d028e292f36fcba8f8b3a28d5e8932754fc2215b9ac69e4cdecf5107c6` |
| `leaflet/images/layers-2x.png` | `066daca850d8ffbef007af00b06eac0015728dee279c51f3cb6c716df7c42edf` |
| `leaflet/images/marker-icon.png` | `574c3a5cca85f4114085b6841596d62f00d7c892c7b03f28cbfa301deb1dc437` |
| `leaflet/images/marker-icon-2x.png` | `00179c4c1ee830d3a108412ae0d294f55776cfeb085c60129a39aa6fc4ae2528` |
| `leaflet/images/marker-shadow.png` | `264f5c640339f042dd729062cfc04c17f8ea0f29882b538e3848ed8f10edb4da` |

Für die Seite „Karte" (Issue #91). Geprüft: der npm-Tarball stimmt mit dem
Shasum der Registry überein (`23fae724e282fa25745aff82ca4d394748db7d8d`),
die Dateien darin haben die Prüfsummen oben. Reines JavaScript ohne
Abhängigkeiten, aktiv gepflegt, kein `eval`/`new Function`. Die einzigen URLs
in `leaflet.js` sind der SVG-Namensraum und der Link „Leaflet" im
Quellenhinweis – den schaltet `public/js/karte.js` ab
(`attributionControl.setPrefix(false)`), der Hinweis „© OpenStreetMap-
Mitwirkende" bleibt. Am Ende der Datei steht ein relativer
`sourceMappingURL=leaflet.js.map`; die Map-Datei wird nicht ausgeliefert, das
betrifft nur die Entwicklerwerkzeuge des Browsers. Leaflet setzt Stile nur über
`element.style` (CSSOM) – das lässt `style-src 'self'` zu; es fügt weder
`<style>`-Elemente noch `style`-Attribute als HTML ein.

Kacheln lädt die Karte ausschließlich von dieser Installation
(`/app/karte/kachel/{z}/{x}/{y}.png`); der Server holt sie von OpenStreetMap
und hält sie zwischen (`App\Service\Karte\KachelProxy`). Der Browser spricht
keinen fremden Host an, die CSP bleibt `'self'`. Die Marker sind
`L.divIcon` mit eigenem CSS (`public/css/teile/karte.css`); die Bilder unter
`images/` liegen nur bei, weil `leaflet.css` auf sie verweist.
