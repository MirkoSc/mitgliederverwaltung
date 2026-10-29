// Pure step-chain logic of public/js/backups.js (issue #12). The DOM wiring
// is not covered here - the file guards its init() behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { statusText, fortschritt, weiter, prozent } = require('../../public/js/backups.js');

test('the chain goes on until the server reports the backup finished', () => {
    for (const phase of ['dump', 'dateien', 'abschluss', 'verschluesseln']) {
        assert.equal(weiter({ phase, fertig: false }), true, phase);
    }
    assert.equal(weiter({ phase: 'fertig', fertig: true }), false);
    assert.equal(weiter(null), false);
});

test('the status line names the phase and its progress', () => {
    assert.equal(statusText({ phase: 'dump', fertig: false, dump_zeilen: 0 }), 'Datenbank wird gesichert …');
    assert.equal(statusText({ phase: 'dump', fertig: false, dump_zeilen: 1200 }), 'Datenbank wird gesichert … (1.200 Zeilen)');
    assert.equal(statusText({ phase: 'dateien', fertig: false, dateien: 200, dateien_gesamt: 450 }), 'Dateien: 200 von 450 gesichert …');
    assert.equal(statusText({ phase: 'dateien', fertig: false, dateien: 0, dateien_gesamt: 0 }), 'Dateien werden gesichert …');
    assert.equal(statusText({ phase: 'abschluss', fertig: false }), 'Backup wird abgeschlossen …');
    assert.equal(statusText({ phase: 'verschluesseln', fertig: false, verschluesselt: 0, zip_bytes: 0 }), 'Backup wird verschlüsselt …');
    assert.equal(statusText({ phase: 'verschluesseln', fertig: false, verschluesselt: 50, zip_bytes: 200 }), 'Backup wird verschlüsselt … (25 %)');
    assert.equal(statusText({ phase: 'fertig', fertig: true }), 'Das Backup ist erstellt.');
    assert.equal(statusText({ phase: '', fertig: false }), 'Backup wird erstellt …');
    assert.equal(statusText(null), '');
});

test('more files than counted at the start never show as more than all', () => {
    assert.equal(statusText({ phase: 'dateien', fertig: false, dateien: 12, dateien_gesamt: 10 }), 'Dateien: 10 von 10 gesichert …');
    assert.equal(fortschritt({ phase: 'dateien', fertig: false, dateien: 12, dateien_gesamt: 10 }), 100);
});

test('the bar has a figure only where the server knows the total', () => {
    assert.equal(fortschritt({ phase: 'dump', fertig: false, dump_zeilen: 5000 }), null);
    assert.equal(fortschritt({ phase: 'abschluss', fertig: false }), null);
    assert.equal(fortschritt({ phase: 'dateien', fertig: false, dateien: 0, dateien_gesamt: 0 }), null);
    assert.equal(fortschritt({ phase: 'dateien', fertig: false, dateien: 1, dateien_gesamt: 3 }), 33);
    assert.equal(fortschritt({ phase: 'verschluesseln', fertig: false, verschluesselt: 0, zip_bytes: 0 }), null);
    assert.equal(fortschritt({ phase: 'verschluesseln', fertig: false, verschluesselt: 3 * 65536, zip_bytes: 4 * 65536 }), 75);
    // The last block is counted as a full chunk - never beyond 100.
    assert.equal(fortschritt({ phase: 'verschluesseln', fertig: false, verschluesselt: 65536, zip_bytes: 100 }), 100);
    assert.equal(fortschritt({ phase: 'fertig', fertig: true }), 100);
    assert.equal(fortschritt(null), null);
    assert.equal(prozent(1, 0), null);
});
