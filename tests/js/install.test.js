// Pure step-chain logic of public/js/install.js (issue #3). The DOM wiring
// is not covered here - the file guards its init() behind `typeof document`.

const test = require('node:test');
const assert = require('node:assert/strict');

const { statusText, weiter, abgelehntText } = require('../../public/js/install.js');

test('the chain starts without an answer and goes on until the server reports it finished', () => {
    assert.equal(weiter(null), true);
    for (const phase of ['entschluesseln', 'auspacken', 'dump', 'dateien', 'migrationen']) {
        assert.equal(weiter({ phase, fertig: false, fortschritt: 10 }), true, phase);
    }
    assert.equal(weiter({ phase: 'fertig', fertig: true, fortschritt: 100 }), false);
});

test('the status line names the phase and the share done', () => {
    assert.equal(statusText({ phase: 'entschluesseln', fertig: false, fortschritt: 12 }), 'Backup wird entschlüsselt … (12 %)');
    assert.equal(statusText({ phase: 'dump', fertig: false, fortschritt: 55 }), 'Datenbank wird eingespielt … (55 %)');
    assert.equal(statusText({ phase: 'dateien', fertig: false }), 'Dateien werden eingespielt …');
    assert.equal(statusText({ phase: 'migrationen', fertig: false, fortschritt: 95 }), 'Datenbank wird auf den neuen Stand gebracht … (95 %)');
    assert.equal(statusText({ phase: 'unbekannt', fertig: false }), 'Backup wird eingespielt …');
    assert.equal(statusText({ phase: 'fertig', fertig: true, fortschritt: 100 }), 'Das Backup ist eingespielt.');
    assert.equal(statusText(null), 'Backup wird eingespielt …');
});

test('files the backup carried under a foreign name are mentioned, in singular or plural', () => {
    assert.equal(abgelehntText(0), '');
    assert.equal(abgelehntText(undefined), '');
    assert.match(abgelehntText(1), /^Eine Datei/);
    assert.match(abgelehntText(3), /^3 Dateien/);
});
