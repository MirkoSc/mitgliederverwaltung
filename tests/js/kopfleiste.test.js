'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { sollVerstecken } = require('../../public/js/kopfleiste.js');

const handy = { schwelle: 100, schmal: true, ruhig: false, menueOffen: false, fokusImKopf: false, versteckt: false };

test('scrolling down past the header hides it on a phone', () => {
    assert.equal(sollVerstecken({ ...handy, vorher: 300, y: 320 }), true);
});

test('the least scrolling up brings it back', () => {
    assert.equal(sollVerstecken({ ...handy, versteckt: true, vorher: 800, y: 799 }), false);
});

test('near the top it stays', () => {
    assert.equal(sollVerstecken({ ...handy, vorher: 40, y: 90 }), false);
    assert.equal(sollVerstecken({ ...handy, versteckt: true, vorher: 120, y: 100 }), false);
});

test('without scrolling nothing changes', () => {
    assert.equal(sollVerstecken({ ...handy, versteckt: true, vorher: 500, y: 500 }), true);
    assert.equal(sollVerstecken({ ...handy, versteckt: false, vorher: 500, y: 500 }), false);
});

test('on a PC it always stays', () => {
    assert.equal(sollVerstecken({ ...handy, schmal: false, vorher: 300, y: 600 }), false);
});

test('Barrierefrei-Modus and reduced motion keep it', () => {
    assert.equal(sollVerstecken({ ...handy, ruhig: true, vorher: 300, y: 600 }), false);
});

test('an open user menu or the focus in the header keeps it', () => {
    assert.equal(sollVerstecken({ ...handy, menueOffen: true, vorher: 300, y: 600 }), false);
    assert.equal(sollVerstecken({ ...handy, fokusImKopf: true, versteckt: true, vorher: 300, y: 600 }), false);
});
