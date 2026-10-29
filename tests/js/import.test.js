// Pure step-chain logic of public/js/import.js (issue #32). The DOM wiring
// is not covered here - the file guards its init() behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { statusText, fortschritt, weiter, prozent } = require('../../public/js/import.js');

test('the chain goes on until the server reports the import finished', () => {
    assert.equal(weiter({ phase: 'import', fertig: false, offset: 0, gesamt: 0 }), true);
    assert.equal(weiter({ phase: 'import', fertig: false, offset: 100, gesamt: 450 }), true);
    assert.equal(weiter({ phase: 'fertig', fertig: true }), false);
    assert.equal(weiter(null), false);
});

test('the status line counts the members imported so far', () => {
    assert.equal(statusText({ fertig: false, offset: 0, gesamt: 0 }), 'Import wird gestartet …');
    assert.equal(statusText({ fertig: false, offset: 200, gesamt: 1450 }), 'Importiert: 200 von 1.450 Mitgliedern …');
    assert.equal(statusText({ fertig: true }), 'Der Import ist abgeschlossen.');
    assert.equal(statusText(null), '');
});

test('the progress is the share of lines done, 100 when finished, none before the total is known', () => {
    assert.equal(fortschritt({ fertig: false, offset: 0, gesamt: 0 }), null);
    assert.equal(fortschritt({ fertig: false, offset: 50, gesamt: 200 }), 25);
    assert.equal(fortschritt({ fertig: true }), 100);
    assert.equal(fortschritt(null), null);
});

test('a count beyond the total never shows as more than all', () => {
    assert.equal(prozent(300, 200), 100);
    assert.equal(prozent(1, 0), null);
    assert.equal(statusText({ fertig: false, offset: 300, gesamt: 200 }), 'Importiert: 200 von 200 Mitgliedern …');
});
