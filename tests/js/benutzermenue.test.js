'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { sollSchliessen } = require('../../public/js/benutzermenue.js');

test('Escape closes an open menu', () => {
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Escape', innerhalb: true, offen: true }), true);
});

test('other keys leave it open', () => {
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Enter', innerhalb: true, offen: true }), false);
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Tab', innerhalb: false, offen: true }), false);
});

test('a click elsewhere closes it, a click inside does not', () => {
    assert.equal(sollSchliessen({ art: 'click', innerhalb: false, offen: true }), true);
    assert.equal(sollSchliessen({ art: 'click', innerhalb: true, offen: true }), false);
});

test('a closed menu stays as it is', () => {
    assert.equal(sollSchliessen({ art: 'keydown', taste: 'Escape', innerhalb: false, offen: false }), false);
    assert.equal(sollSchliessen({ art: 'click', innerhalb: false, offen: false }), false);
});
