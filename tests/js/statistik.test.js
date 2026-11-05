// Pure helpers of public/js/statistik.js (page "Statistik", 09 section 7).
// The Chart.js wiring is not covered here - the file guards its init behind
// `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { euro, zahl, kuerzen, wertText, achsenText, hoehe, farbenAus, daten, konfiguration } = require('../../public/js/statistik.js');

const NBSP = ' ';
const farben = farbenAus((name) => ({
    '--diagramm-1': '#111111',
    '--diagramm-2': '#222222',
    '--diagramm-3': '#333333',
    '--diagramm-4': '#444444',
    '--farbe-text': '#000000',
    '--farbe-gedaempft': '#555555',
    '--farbe-rand': '#cccccc',
    '--farbe-flaeche': '#ffffff',
    '--schrift': 'sans-serif',
})[name]);

test('values read in German: euros with cents, counts with thousands', () => {
    assert.equal(euro(123450), '1.234,50' + NBSP + '€');
    assert.equal(zahl(1234), '1.234');
    assert.equal(wertText(1234.5, 'cent'), '1.234,50' + NBSP + '€');
    assert.equal(wertText(7, 'anzahl'), '7');
    assert.equal(achsenText(1200, 'cent'), '1.200' + NBSP + '€');
});

test('long names on the axis are shortened', () => {
    assert.equal(kuerzen('Musterstadt', 18), 'Musterstadt');
    assert.equal(kuerzen('Jugend/Schüler/Studenten/Azubis', 18), 'Jugend/Schüler/St…');
});

test('horizontal bars grow with their rows, other charts keep their height', () => {
    assert.equal(hoehe('balken', 12), 28);
    assert.equal(hoehe('gestapelt-liegend', 1), 8);
    assert.equal(hoehe('saeulen', 30), 16);
    assert.equal(hoehe('ring', 2), 14);
});

test('the colours come from the tokens', () => {
    assert.equal(farben[1], '#111111');
    assert.equal(farben[4], '#444444');
    assert.equal(farben.text, '#000000');
});

test('a single series is drawn in slot 1, its rest rows in grey, money in euros', () => {
    const d = daten('balken', [{ name: 'Summe', farbe: 1 }], [
        { name: 'Musterstadt', werte: [12345], ohne: false },
        { name: 'Sonstige (3 Orte)', werte: [500], ohne: true },
    ], 'cent', farben);

    assert.deepEqual(d.labels, ['Musterstadt', 'Sonstige (3 Orte)']);
    assert.equal(d.datasets.length, 1);
    assert.deepEqual(d.datasets[0].data, [123.45, 5]);
    assert.deepEqual(d.datasets[0].backgroundColor, ['#111111', '#444444']);
});

test('several series keep their own slot - the colour follows the series', () => {
    const reihen = [
        { name: 'weiblich', farbe: 1 },
        { name: 'männlich', farbe: 2 },
        { name: 'divers', farbe: 3 },
        { name: 'ohne Angabe', farbe: 4 },
    ];
    const d = daten('gestapelt', reihen, [{ name: '0–6', werte: [1, 2, 0, 3], ohne: false }], 'anzahl', farben);

    assert.deepEqual(d.datasets.map((s) => s.backgroundColor), ['#111111', '#222222', '#333333', '#444444']);
    assert.deepEqual(d.datasets.map((s) => s.data[0]), [1, 2, 0, 3]);
});

test('a ring colours its parts in order, a rest part grey', () => {
    const d = daten('ring', [{ name: 'Mitglieder', farbe: 1 }], [
        { name: 'in einer Familie', werte: [4], ohne: false },
        { name: 'einzeln', werte: [9], ohne: false },
        { name: 'Mitglied unbekannt', werte: [1], ohne: true },
    ], 'anzahl', farben);

    assert.deepEqual(d.datasets[0].backgroundColor, ['#111111', '#222222', '#444444']);
});

test('the configuration: orientation, stacking, legend only where it names something', () => {
    const eine = [{ name: 'Mitglieder', farbe: 1 }];
    const zeilen = [{ name: 'A', werte: [1, 2], ohne: false }];

    const balken = konfiguration('balken', eine, zeilen, 'anzahl', farben);
    assert.equal(balken.type, 'bar');
    assert.equal(balken.options.indexAxis, 'y');
    assert.equal(balken.options.plugins.legend.display, false);
    assert.equal(balken.options.maintainAspectRatio, false);
    assert.equal(balken.options.scales.x.beginAtZero, true);

    const gestapelt = konfiguration('gestapelt', [{ name: 'a', farbe: 1 }, { name: 'b', farbe: 2 }], zeilen, 'anzahl', farben);
    assert.equal(gestapelt.options.indexAxis, 'x');
    assert.equal(gestapelt.options.scales.x.stacked, true);
    assert.equal(gestapelt.options.scales.y.stacked, true);
    assert.equal(gestapelt.options.plugins.legend.display, true);

    const gruppiert = konfiguration('gruppiert', [{ name: 'a', farbe: 1 }, { name: 'b', farbe: 3 }], zeilen, 'anzahl', farben);
    assert.equal(gruppiert.options.scales.y.stacked, false);

    const ring = konfiguration('ring', eine, zeilen, 'anzahl', farben);
    assert.equal(ring.type, 'doughnut');
    assert.equal(ring.options.scales, undefined);
    assert.equal(ring.options.plugins.legend.display, true);

    const tooltip = konfiguration('gruppiert', [{ name: 'Eintritte', farbe: 1 }, { name: 'Austritte', farbe: 3 }], zeilen, 'anzahl', farben).options.plugins.tooltip.callbacks.label;
    assert.equal(tooltip({ raw: 3, dataset: { label: 'Austritte' } }), 'Austritte: 3');
    const geld = konfiguration('balken', [{ name: 'Summe', farbe: 1 }], zeilen, 'cent', farben).options.plugins.tooltip.callbacks.label;
    assert.equal(geld({ raw: 85, dataset: { label: 'Summe' } }), '85,00' + NBSP + '€');
});
