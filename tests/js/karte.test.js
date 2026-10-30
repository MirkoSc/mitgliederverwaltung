// Pure helpers of public/js/karte.js (issue #91). The Leaflet wiring is not
// covered here - the file guards its init behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { punktText, punktGroesse, punktTitel, orteAus, wohnortAus, statusText, weiter } = require('../../public/js/karte.js');

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
