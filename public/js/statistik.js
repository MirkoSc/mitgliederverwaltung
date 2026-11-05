/*
 * Charts of the page "Statistik" (app/views/statistik/,
 * docs/spec/09-listen-und-exporte.md section 7).
 *
 * The table is the source: every table with data-diagramm gets a chart
 * (Chart.js, vendored) drawn from its rows - the series from
 * th[data-reihe]/data-farbe, the raw values from td[data-wert], rest rows
 * (data-ohne) in grey. Then the table folds away behind "Zahlen als
 * Tabelle". Without this script, without Chart.js and in the
 * Barrierefrei-Modus there are only the tables.
 *
 * Colours come from the design tokens (--diagramm-1..4, --farbe-text …,
 * public/css/teile/statistik.css), read with getComputedStyle; when the
 * device switches between light and dark, every chart is drawn anew.
 * The pure helpers are tested with node --test (tests/js/statistik.test.js).
 */
(function () {
    'use strict';

    var LIEGEND = { balken: true, 'gestapelt-liegend': true };
    var GESTAPELT = { gestapelt: true, 'gestapelt-liegend': true };

    /** "1.234,50 €" */
    function euro(cent) {
        return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(cent / 100);
    }

    /** "1.234" */
    function zahl(wert) {
        return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 0 }).format(wert);
    }

    /**
     * A value as text. In the chart money travels in euros (Chart.js scales
     * floats); the table and its data-wert keep integer cent.
     *
     * @param {number} wert euros for "cent", else a count
     * @param {string} einheit "cent" or "anzahl"
     */
    function wertText(wert, einheit) {
        return einheit === 'cent' ? euro(Math.round(wert * 100)) : zahl(wert);
    }

    /** Axis labels: euros without cents. */
    function achsenText(wert, einheit) {
        return einheit === 'cent'
            ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(wert)
            : zahl(wert);
    }

    /** Long group names on the axis end in "…" - the tooltip and the table keep them whole. */
    function kuerzen(text, laenge) {
        text = String(text);
        return text.length > laenge ? text.slice(0, laenge - 1).trimEnd() + '…' : text;
    }

    /** Height of the chart in rem: horizontal bars grow with their rows. */
    function hoehe(typ, zeilen) {
        if (LIEGEND[typ]) {
            return Math.max(8, 4 + 2 * zeilen);
        }
        return typ === 'ring' ? 14 : 16;
    }

    /**
     * The colours from the tokens.
     *
     * @param {function(string): string} lesen reads a custom property
     */
    function farbenAus(lesen) {
        return {
            1: lesen('--diagramm-1'),
            2: lesen('--diagramm-2'),
            3: lesen('--diagramm-3'),
            4: lesen('--diagramm-4'),
            text: lesen('--farbe-text'),
            gedaempft: lesen('--farbe-gedaempft'),
            rand: lesen('--farbe-rand'),
            flaeche: lesen('--farbe-flaeche'),
            schrift: lesen('--schrift'),
        };
    }

    /**
     * Chart.js data from the table. A single series takes slot 1, its rest
     * rows grey; a ring takes slot 1, 2, 3 … per row; several series their
     * own slot each.
     *
     * @param {string} typ data-diagramm
     * @param {{name: string, farbe: number}[]} reihen
     * @param {{name: string, werte: number[], ohne: boolean}[]} zeilen
     * @param {string} einheit "cent" or "anzahl"
     * @param {object} farben from farbenAus()
     */
    function daten(typ, reihen, zeilen, einheit, farben) {
        var teiler = einheit === 'cent' ? 100 : 1;
        var labels = zeilen.map(function (z) { return z.name; });
        var datasets = reihen.map(function (reihe, i) {
            var werte = zeilen.map(function (z) { return (z.werte[i] || 0) / teiler; });
            var farbe;
            if (typ === 'ring') {
                var slot = 0;
                farbe = zeilen.map(function (z) {
                    if (z.ohne) {
                        return farben[4];
                    }
                    slot = slot % 3 + 1;
                    return farben[slot];
                });
            } else if (reihen.length === 1) {
                farbe = zeilen.map(function (z) { return z.ohne ? farben[4] : farben[reihe.farbe] || farben[1]; });
            } else {
                farbe = farben[reihe.farbe] || farben[1];
            }
            return {
                label: reihe.name,
                data: werte,
                backgroundColor: farbe,
                borderColor: farben.flaeche,
                // A thin gap in the surface colour between ring parts and
                // stacked segments; single bars get rounded ends instead.
                borderWidth: typ === 'ring' ? 2 : (GESTAPELT[typ] ? 1 : 0),
                borderRadius: typ === 'ring' || GESTAPELT[typ] ? 0 : 4,
                borderSkipped: GESTAPELT[typ] ? false : 'start',
                maxBarThickness: 32,
            };
        });
        return { labels: labels, datasets: datasets };
    }

    /** The whole Chart.js configuration of one table. */
    function konfiguration(typ, reihen, zeilen, einheit, farben) {
        var mehrere = reihen.length > 1;
        var achse = {
            grid: { color: farben.rand },
            border: { color: farben.rand },
            ticks: { color: farben.gedaempft, precision: 0 },
        };
        var wertAchse = Object.assign({}, achse, {
            beginAtZero: true,
            stacked: !!GESTAPELT[typ],
            ticks: {
                color: farben.gedaempft,
                precision: 0,
                callback: function (wert) { return achsenText(wert, einheit); },
            },
        });
        var gruppenAchse = Object.assign({}, achse, {
            stacked: !!GESTAPELT[typ],
            grid: { display: false },
            ticks: {
                color: farben.gedaempft,
                callback: function (wert) { return kuerzen(this.getLabelForValue(wert), 18); },
            },
        });
        var liegend = !!LIEGEND[typ];

        var optionen = {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: {
                    display: mehrere || typ === 'ring',
                    position: 'top',
                    align: 'start',
                    labels: { color: farben.text, boxWidth: 12, boxHeight: 12 },
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var text = wertText(ctx.raw, einheit);
                            return mehrere ? ctx.dataset.label + ': ' + text : text;
                        },
                    },
                },
            },
        };
        if (typ === 'ring') {
            optionen.cutout = '60%';
        } else {
            optionen.indexAxis = liegend ? 'y' : 'x';
            optionen.scales = liegend ? { x: wertAchse, y: gruppenAchse } : { x: gruppenAchse, y: wertAchse };
        }

        return {
            type: typ === 'ring' ? 'doughnut' : 'bar',
            data: daten(typ, reihen, zeilen, einheit, farben),
            options: optionen,
        };
    }

    /** Series and rows of one table, read from the DOM. */
    function tabelleLesen(tabelle) {
        var reihen = Array.prototype.map.call(tabelle.querySelectorAll('thead th[data-reihe]'), function (th) {
            return { name: th.getAttribute('data-reihe'), farbe: Number(th.getAttribute('data-farbe')) || 1 };
        });
        var zeilen = Array.prototype.map.call(tabelle.querySelectorAll('tbody tr'), function (tr) {
            var erste = tr.querySelector('td');
            return {
                name: erste ? erste.textContent.trim() : '',
                ohne: tr.hasAttribute('data-ohne'),
                werte: Array.prototype.map.call(tr.querySelectorAll('td[data-wert]'), function (td) {
                    return Number(td.getAttribute('data-wert')) || 0;
                }),
            };
        });
        return { reihen: reihen, zeilen: zeilen };
    }

    function init() {
        var tabellen = document.querySelectorAll('table[data-diagramm]');
        if (tabellen.length === 0 || typeof window.Chart !== 'function') {
            return;
        }
        if (document.documentElement.classList.contains('modus-barrierefrei')) {
            return;
        }

        var diagramme = [];
        var zeichnen = function () {
            var stil = getComputedStyle(document.documentElement);
            var farben = farbenAus(function (name) { return stil.getPropertyValue(name).trim(); });
            window.Chart.defaults.font.family = farben.schrift || window.Chart.defaults.font.family;
            window.Chart.defaults.color = farben.gedaempft;
            diagramme.forEach(function (d) {
                if (d.chart) {
                    d.chart.destroy();
                }
                d.chart = new window.Chart(d.canvas, konfiguration(d.typ, d.daten.reihen, d.daten.zeilen, d.einheit, farben));
            });
        };

        Array.prototype.forEach.call(tabellen, function (tabelle) {
            var details = tabelle.closest('details');
            var typ = tabelle.getAttribute('data-diagramm');
            var gelesen = tabelleLesen(tabelle);
            var rahmen = document.createElement('div');
            rahmen.className = 'statistik-diagramm';
            rahmen.style.height = hoehe(typ, gelesen.zeilen.length) + 'rem';
            var canvas = document.createElement('canvas');
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', 'Diagramm „' + (tabelle.getAttribute('data-titel') || '') + '“ – dieselben Zahlen stehen in der Tabelle darunter.');
            rahmen.appendChild(canvas);
            (details || tabelle).before(rahmen);
            if (details) {
                details.open = false;
            }
            diagramme.push({ canvas: canvas, typ: typ, daten: gelesen, einheit: tabelle.getAttribute('data-einheit'), chart: null });
        });
        zeichnen();

        if (typeof window.matchMedia === 'function') {
            var dunkel = window.matchMedia('(prefers-color-scheme: dark)');
            if (typeof dunkel.addEventListener === 'function') {
                dunkel.addEventListener('change', zeichnen);
            }
        }
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }

    // Node (tests/js) loads the same file for the pure helpers above.
    if (typeof module === 'object' && module.exports) {
        module.exports = { euro, zahl, kuerzen, wertText, achsenText, hoehe, farbenAus, daten, konfiguration };
    }
})();
