// Pure step-chain logic of public/js/update.js (CLAUDE.md section 8: JS
// logic is tested with node --test). The DOM wiring is not covered here -
// the file guards its init() behind `typeof document`, so requiring it in
// node loads the helpers only.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    naechsteSchritte,
    abSchritt,
    zurueckrollenAngebracht,
    letzteMeldung,
    nochmal,
    schrittName,
} = require('../../public/js/update.js');

const SCHRITTE = ['check', 'download', 'extract', 'backup', 'switch', 'migrate', 'finish'];

test('after the version check the rest of the chain follows', () => {
    assert.deepEqual(naechsteSchritte(SCHRITTE, 'check'), [
        'download',
        'extract',
        'backup',
        'switch',
        'migrate',
        'finish',
    ]);
});

test('nothing is left after the last step', () => {
    assert.deepEqual(naechsteSchritte(SCHRITTE, 'finish'), []);
});

// A state file written by an older release can name a step this version
// does not know. Running the whole chain is the safe answer: every step is
// idempotent, whereas skipping steps would switch releases without a
// download.
test('an unknown step means the whole chain runs', () => {
    assert.deepEqual(naechsteSchritte(SCHRITTE, 'nachbessern'), SCHRITTE);
    assert.deepEqual(naechsteSchritte(SCHRITTE, undefined), SCHRITTE);
});

test('a retry repeats the failed step and everything after it', () => {
    assert.deepEqual(abSchritt(SCHRITTE, 'switch'), ['switch', 'migrate', 'finish']);
    assert.deepEqual(abSchritt(SCHRITTE, 'check'), SCHRITTE);
});

test('a retry of an unknown step does nothing', () => {
    assert.deepEqual(abSchritt(SCHRITTE, 'rollback'), []);
});

test('the step log shows the last line of the state', () => {
    assert.equal(letzteMeldung({ meldungen: ['erste', 'zweite'] }), 'zweite');
    assert.equal(letzteMeldung({ meldungen: [] }), '');
    assert.equal(letzteMeldung({}), '');
    assert.equal(letzteMeldung(null), '');
});

test('naechsteSchritte does not modify the list it was given', () => {
    const kopie = SCHRITTE.slice();
    naechsteSchritte(kopie, 'check');
    assert.deepEqual(kopie, SCHRITTE);
});

// The backup step answers `weiter` while its dump is not
// through; the page then calls the same step again.
test('a step answering weiter is called again, any other answer moves on', () => {
    assert.equal(nochmal({ weiter: true, fehler: null }), true);
    assert.equal(nochmal({ weiter: false, fehler: null }), false);
    assert.equal(nochmal({ fehler: null }), false, 'an older server never says weiter');
    assert.equal(nochmal({ weiter: true, fehler: 'kaputt' }), false, 'an error ends the step');
    assert.equal(nochmal(null), false);
});

// releases/_prev is only the release we came from once the switch happened.
// Before that it belongs to an older update, and "rolling back" would be a
// downgrade.
test('rollback is only offered once the new release is running', () => {
    for (const schritt of ['check', 'download', 'extract', 'backup', 'switch']) {
        assert.equal(zurueckrollenAngebracht(SCHRITTE, schritt), false, schritt);
    }
    for (const schritt of ['migrate', 'finish']) {
        assert.equal(zurueckrollenAngebracht(SCHRITTE, schritt), true, schritt);
    }
    assert.equal(zurueckrollenAngebracht(SCHRITTE, 'rollback'), false);
    assert.equal(zurueckrollenAngebracht(SCHRITTE, undefined), false);
});

// A page reload resumes the chain after the last step that passed; the
// state file is written by the server, so the page only passes that name on.
test('a resumed chain starts after the last completed step', () => {
    assert.deepEqual(naechsteSchritte(SCHRITTE, 'backup'), ['switch', 'migrate', 'finish']);
});

test('every step of the chain has a German name', () => {
    for (const schritt of SCHRITTE) {
        assert.notEqual(schrittName(schritt), schritt, schritt);
    }
    assert.equal(schrittName('unbekannt'), 'unbekannt');
});
