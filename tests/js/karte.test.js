// Pure helpers of public/js/karte.js (issue #91). The Leaflet wiring is not
// covered here - the file guards its init behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { punktText, punktGroesse, punktTitel, orteAus, wohnortAus, mitteAus, punktAusFeldern, feldText, statusText, weiter } = require('../../public/js/karte.js');

test('a marker shows how many members live at the address', () => {
    assert.equal(punktText(1), '1');
    assert.equal(punktText('3'), '3');
    assert.equal(punktText(0), '1');
    assert.equal(punktText(150), '99+');
    assert.equal(punktGroesse(1) < punktGroesse(2), true);
    assert.equal(punktTitel('Hauptstraße 1, 12345 Musterstadt', 1), 'Hauptstraße 1, 12345 Musterstadt: 1 Mitglied');
    assert.equal(punktTitel('Hauptstraße 1, 12345 Musterstadt', 3), 'Hauptstraße 1, 12345 Musterstadt: 3 Mitglieder');
});

test('the places come from the table rows, invalid coordinates are left out', () => {
    const orte = orteAus([
        { lat: '48.137154', lon: '11.576124', anschrift: 'A', anzahl: '2' },
        { lat: '', lon: '11.5', anschrift: 'B', anzahl: '1' },
        { lat: '91', lon: '11.5', anschrift: 'C', anzahl: '1' },
        { lat: '48.1', lon: 'x', anschrift: 'D', anzahl: '1' },
        { lat: '-33.9', lon: '151.2', anschrift: 'E', anzahl: '' },
    ]);

    assert.deepEqual(orte.map((o) => [o.anschrift, o.lat, o.lon, o.anzahl]), [
        ['A', 48.137154, 11.576124, 2],
        ['E', -33.9, 151.2, 1],
    ]);
});

test('the chain goes on until nothing is open or the service rests', () => {
    assert.equal(weiter({ offen: 3, fertig: false }), true);
    assert.equal(weiter({ offen: 0, fertig: true }), false);
    assert.equal(weiter({ offen: 3, fertig: true, pause_bis: '12:30' }), false);
    assert.equal(weiter({ offen: 3, fertig: false, laeuft_bereits: true }), true);
    assert.equal(weiter(null), false);
});

test('the status line says what happens', () => {
    assert.equal(statusText({ offen: 4, text: '6 von 10 Anschriften gefunden' }), '6 von 10 Anschriften gefunden – noch 4 offen …');
    assert.equal(statusText({ offen: 0, text: '10 von 10 Anschriften gefunden' }), 'Alle Anschriften sind ermittelt.');
    assert.equal(statusText({ offen: 4, pause_bis: '12:30' }), 'Der Kartendienst antwortet gerade nicht. Neuer Versuch ab 12:30 Uhr.');
    assert.equal(statusText({ offen: 4, laeuft_bereits: true }), 'Die Ermittlung läuft gerade im Hintergrund – einen Moment …');
    assert.equal(statusText(null), '');
});

test('the small map at a member has one place, or none with broken coordinates (issue #106)', () => {
    const ort = wohnortAus({ lat: '48.137154', lon: '11.576124', titel: 'Hauptstraße 1, 12345 Musterstadt' });
    assert.deepEqual([ort.lat, ort.lon, ort.anschrift, ort.anzahl], [48.137154, 11.576124, 'Hauptstraße 1, 12345 Musterstadt', 1]);
    assert.equal(punktTitel(ort.anschrift, ort.anzahl), 'Hauptstraße 1, 12345 Musterstadt: 1 Mitglied');
    assert.equal(wohnortAus({ lat: '', lon: '11.5', titel: 'x' }), null);
    assert.equal(wohnortAus({ lat: '48.1', lon: '200', titel: 'x' }), null);
});

test('a row marked for focus keeps the mark (issue #106)', () => {
    const orte = orteAus([
        { lat: '48.1', lon: '11.5', anschrift: 'A', anzahl: '1' },
        { lat: '48.2', lon: '11.6', anschrift: 'B', anzahl: '1', fokus: true },
    ]);
    assert.deepEqual(orte.map((o) => o.fokus), [false, true]);
});

test('an approximate or hand-set dot says so in its name (issue #108)', () => {
    assert.equal(punktTitel('Am Hang 12, 63867 Johannesberg', 1, 'ungefaehr'), 'Am Hang 12, 63867 Johannesberg: 1 Mitglied (ungefähr)');
    assert.equal(punktTitel('Am Hang 12, 63867 Johannesberg', 2, 'von_hand'), 'Am Hang 12, 63867 Johannesberg: 2 Mitglieder (von Hand)');
    assert.equal(punktTitel('A', 1, 'irgendwas'), 'A: 1 Mitglied');
    const orte = orteAus([
        { lat: '50.1', lon: '9.1', anschrift: 'A', anzahl: '1', genauigkeit: 'ungefaehr' },
        { lat: '50.2', lon: '9.2', anschrift: 'B', anzahl: '1', genauigkeit: '<script>' },
    ]);
    assert.deepEqual(orte.map((o) => o.genauigkeit), ['ungefaehr', '']);
    assert.equal(wohnortAus({ lat: '50.1', lon: '9.1', titel: 'A', genauigkeit: 'von_hand' }).genauigkeit, 'von_hand');
});

test('a small map without a dot opens at its middle (issue #108)', () => {
    assert.deepEqual(mitteAus({ mitteLat: '50.032900', mitteLon: '9.142400', mitteZoom: '14' }), { lat: 50.0329, lon: 9.1424, zoom: 14 });
    assert.deepEqual(mitteAus({ mitteLat: '51', mitteLon: '10' }), { lat: 51, lon: 10, zoom: 13 });
    assert.equal(mitteAus({}), null);
    assert.equal(mitteAus({ mitteLat: '91', mitteLon: '10' }), null);
});

test('the fields of a point by hand take a decimal comma and refuse anything else (issue #108)', () => {
    assert.deepEqual(punktAusFeldern('50,0329', ' 9.1424 '), { lat: 50.0329, lon: 9.1424 });
    assert.deepEqual(punktAusFeldern('-90', '180'), { lat: -90, lon: 180 });
    assert.equal(punktAusFeldern('90.1', '9'), null);
    assert.equal(punktAusFeldern('50', '-180.5'), null);
    assert.equal(punktAusFeldern('', '9'), null);
    assert.equal(punktAusFeldern('5e1', '9'), null);
    assert.equal(punktAusFeldern('50,1,2', '9'), null);
    assert.equal(feldText(50.03290012), '50.032900');
    assert.equal(feldText(-9.5), '-9.500000');
});
