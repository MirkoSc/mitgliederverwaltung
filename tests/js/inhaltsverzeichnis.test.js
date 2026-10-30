'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { aktiverAbschnitt } = require('../../public/js/inhaltsverzeichnis.js');

const seite = (...oben) => oben.map((o, i) => ({ id: 'a' + i, oben: o }));

test('no sections: nothing is active', () => {
    assert.strictEqual(aktiverAbschnitt([], 100), null);
});

test('before the first section has reached the line, the first is active', () => {
    assert.strictEqual(aktiverAbschnitt(seite(300, 900, 1500), 100), 'a0');
});

test('the last section above the line is active', () => {
    assert.strictEqual(aktiverAbschnitt(seite(-800, -100, 60, 700), 100), 'a2');
});

test('a section exactly on the line counts', () => {
    assert.strictEqual(aktiverAbschnitt(seite(-500, 100, 800), 100), 'a1');
});

test('at the end of the page the last section is active', () => {
    assert.strictEqual(aktiverAbschnitt(seite(-3000, -2000, -50), 100), 'a2');
});
