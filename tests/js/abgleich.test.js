// Pure logic of public/js/abgleich.js (issue #34). The DOM wiring is not
// covered here - the file guards its init behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { liste, anzahlGewaehlt, statusText, fortschritt, weiter } = require('../../public/js/abgleich.js');

test('the ticks of a section go as one comma-separated list of numbers', () => {
    assert.equal(liste(['12', '7', '1001']), '12,7,1001');
    assert.equal(liste([]), '');
    assert.equal(liste(undefined), '');
    assert.equal(liste(['12', 'x', '', '3a', '4']), '12,4');
});

test('a row ticked for leaving and for deleting counts once', () => {
    assert.equal(anzahlGewaehlt({ neu: ['1', '2'], aendern: ['3'], austritt: ['4', '5'], loeschen: ['5', '6'] }), 6);
    assert.equal(anzahlGewaehlt({ neu: [], aendern: [], austritt: [], loeschen: [] }), 0);
    assert.equal(anzahlGewaehlt({}), 0);
});

test('the chain goes on until the server reports the reconciliation finished', () => {
    assert.equal(weiter({ phase: 'abgleich', fertig: false, offset: 0, gesamt: 0 }), true);
    assert.equal(weiter({ phase: 'abgleich', fertig: false, offset: 100, gesamt: 230 }), true);
    assert.equal(weiter({ phase: 'fertig', fertig: true }), false);
    assert.equal(weiter(null), false);
});

test('the status line and the progress count the rows taken over', () => {
    assert.equal(statusText({ fertig: false, offset: 0, gesamt: 0 }), 'Abgleich wird gestartet …');
    assert.equal(statusText({ fertig: false, offset: 100, gesamt: 1250 }), 'Übernommen: 100 von 1.250 Zeilen …');
    assert.equal(statusText({ fertig: true }), 'Der Abgleich ist abgeschlossen.');
    assert.equal(fortschritt({ fertig: false, offset: 50, gesamt: 200 }), 25);
    assert.equal(fortschritt({ fertig: false, offset: 300, gesamt: 200 }), 100);
    assert.equal(fortschritt({ fertig: true }), 100);
    assert.equal(fortschritt({ fertig: false, offset: 0, gesamt: 0 }), null);
});
