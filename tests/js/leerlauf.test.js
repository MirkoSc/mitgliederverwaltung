'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { phase } = require('../../public/js/leerlauf.js');

test('quiet well before the limit', () => {
    assert.equal(phase(0, 3600), 'ruhig');
    assert.equal(phase(3000, 3600), 'ruhig');
});

test('warns five minutes before the limit', () => {
    assert.equal(phase(3300, 3600), 'warnung');
    assert.equal(phase(3599, 3600), 'warnung');
});

test('expired at the limit', () => {
    assert.equal(phase(3600, 3600), 'abgelaufen');
});
