'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { sollSchliessen } = require('../../public/js/benutzermenue.js');

test('Escape schließt ein offenes Menü', () => {
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Escape', innerhalb: true, offen: true }), true);
});

test('andere Tasten lassen es offen', () => {
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Enter', innerhalb: true, offen: true }), false);
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Tab', innerhalb: false, offen: true }), false);
});

test('ein Klick daneben schließt, ein Klick im Menü nicht', () => {
    assert.equal(sollSchliessen({ art: 'click', innerhalb: false, offen: true }), true);
    assert.equal(sollSchliessen({ art: 'click', innerhalb: true, offen: true }), false);
});

test('ein geschlossenes Menü bleibt unberührt', () => {
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Escape', innerhalb: false, offen: false }), false);
    assert.equal(sollSchliessen({ art: 'click', innerhalb: false, offen: false }), false);
});
