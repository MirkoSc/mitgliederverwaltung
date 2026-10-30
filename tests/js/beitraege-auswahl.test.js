'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { bereich, anzahl, zaehlerText, alleZustand } = require('../../public/js/beitraege-auswahl.js');

const AUS = [false, false, false, false, false, false];

test('a click without Shift changes only the clicked box', () => {
    assert.deepEqual(bereich(AUS, 0, 3, true, false), [false, false, false, true, false, false]);
    assert.deepEqual(bereich([true, true, true], 0, 1, false, false), [true, false, true]);
});

test('Shift without an earlier click changes only the clicked box', () => {
    assert.deepEqual(bereich(AUS, null, 2, true, true), [false, false, true, false, false, false]);
});

test('Shift selects the range from the last click to this one, downwards', () => {
    assert.deepEqual(bereich(AUS, 1, 4, true, true), [false, true, true, true, true, false]);
});

test('Shift selects the range upwards as well', () => {
    assert.deepEqual(bereich(AUS, 4, 1, true, true), [false, true, true, true, true, false]);
});

test('the range takes the state of the clicked box, the others stay as they are', () => {
    const vorher = [true, false, true, true, false, true];
    // Box 3 was just unticked: 1..3 become unticked, 0, 4 and 5 keep theirs.
    assert.deepEqual(bereich(vorher, 1, 3, false, true), [true, false, false, false, false, true]);
    // Box 1 was just ticked from 4: 1..4 ticked, 0 and 5 untouched.
    assert.deepEqual(bereich([false, true, false, false, false, false], 4, 1, true, true), [false, true, true, true, true, false]);
});

test('Shift on the same box as last time changes just that box', () => {
    assert.deepEqual(bereich(AUS, 2, 2, true, true), [false, false, true, false, false, false]);
});

test('the input array is not changed', () => {
    const vorher = AUS.slice();
    bereich(vorher, 0, 5, true, true);
    assert.deepEqual(vorher, AUS);
});

test('an index outside the list changes nothing, a stale last index is ignored', () => {
    assert.deepEqual(bereich(AUS, 0, 9, true, true), AUS);
    assert.deepEqual(bereich(AUS, 9, 2, true, true), [false, false, true, false, false, false]);
});

test('counter and "Alle auswählen"', () => {
    assert.equal(anzahl([true, false, true]), 2);
    assert.equal(zaehlerText(0), '0 ausgewählt');
    assert.equal(zaehlerText(3), '3 ausgewählt');
    assert.equal(alleZustand([false, false]), 'keine');
    assert.equal(alleZustand([true, false]), 'teils');
    assert.equal(alleZustand([true, true]), 'alle');
});
