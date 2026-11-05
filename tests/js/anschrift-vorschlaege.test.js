'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { sollSuchen, naechsterIndex, felderFuer, vorschlaegeAus, ansage } = require('../../public/js/anschrift-vorschlaege.js');

test('asks from three characters on, blanks do not count', () => {
    assert.equal(sollSuchen('Ha'), false);
    assert.equal(sollSuchen('  Ha  '), false);
    assert.equal(sollSuchen('Hau'), true);
    assert.equal(sollSuchen(undefined), false);
});

test('arrow keys move through the options and wrap around', () => {
    assert.equal(naechsterIndex(-1, 3, 'ArrowDown'), 0);
    assert.equal(naechsterIndex(0, 3, 'ArrowDown'), 1);
    assert.equal(naechsterIndex(2, 3, 'ArrowDown'), 0);
    assert.equal(naechsterIndex(-1, 3, 'ArrowUp'), 2);
    assert.equal(naechsterIndex(0, 3, 'ArrowUp'), 2);
    assert.equal(naechsterIndex(2, 3, 'ArrowUp'), 1);
    assert.equal(naechsterIndex(1, 3, 'Home'), 0);
    assert.equal(naechsterIndex(1, 3, 'End'), 2);
    assert.equal(naechsterIndex(-1, 0, 'ArrowDown'), -1);
    assert.equal(naechsterIndex(1, 3, 'x'), 1);
});

test('a suggestion fills only the fields it has a value for', () => {
    assert.deepEqual(
        felderFuer({ strasse: 'Hauptstraße 5', plz: '12345', ort: 'Musterstadt', land: 'DE', text: 'x' }),
        { strasse: 'Hauptstraße 5', plz: '12345', ort: 'Musterstadt', land: 'DE' },
    );
    assert.deepEqual(felderFuer({ strasse: '', plz: '', ort: 'Musterstadt', land: 'AT' }), { ort: 'Musterstadt', land: 'AT' });
    assert.deepEqual(felderFuer(null), {});
    assert.deepEqual(felderFuer({ strasse: 5 }), {});
});

test('reads the suggestions defensively', () => {
    assert.deepEqual(vorschlaegeAus(null), []);
    assert.deepEqual(vorschlaegeAus({ fehler: 'x' }), []);
    assert.deepEqual(vorschlaegeAus({ vorschlaege: 'x' }), []);
    const gut = { strasse: 'A 1', plz: '', ort: '', land: '', text: 'A 1' };
    assert.deepEqual(vorschlaegeAus({ vorschlaege: [gut, null, { text: '' }, { strasse: 'B' }] }), [gut]);
});

test('announces the number of suggestions', () => {
    assert.equal(ansage(0), 'Keine Anschriftvorschläge.');
    assert.match(ansage(1), /^Ein Anschriftvorschlag\./);
    assert.match(ansage(4), /^4 Anschriftvorschläge\./);
});
