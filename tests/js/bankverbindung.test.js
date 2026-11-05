'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const b = require('../../public/js/bankverbindung.js');

test('normalises an IBAN', () => {
    assert.equal(b.ibanNormalisieren(' de89 3704-0044 0532 0130 00 '), 'DE89370400440532013000');
});

test('valid IBANs, as App\\Support\\Iban', () => {
    for (const iban of [
        'DE89370400440532013000',
        'DE02120300000000202051',
        'de02 1203 0000 0000 2020 51',
        'AT611904300234573201',
        'NL91ABNA0417164300',
        'GB29NWBK60161331926819',
    ]) {
        assert.equal(b.ibanGueltig(iban), true, iban);
    }
});

test('invalid IBANs', () => {
    for (const iban of [
        '',
        'DE00123456789012345678',
        'DE8937040044053201300',
        'DE893704004405320130000',
        'DE02100208900014569054',
        'XX',
        '89370400440532013000DE',
    ]) {
        assert.equal(b.ibanGueltig(iban), false, iban);
    }
});

test('status while typing', () => {
    assert.equal(b.ibanStatus(''), 'leer');
    assert.equal(b.ibanStatus('D'), 'unvollstaendig');
    assert.equal(b.ibanStatus('DE89 3704'), 'unvollstaendig');
    assert.equal(b.ibanStatus('DE89 3704 0044 0532 0130 00'), 'gueltig');
    assert.equal(b.ibanStatus('DE00 1234 5678 9012 3456 78'), 'ungueltig');
    assert.equal(b.ibanStatus('DE89 3704 0044 0532 0130 000'), 'ungueltig', 'too long');
    assert.equal(b.ibanStatus('1234'), 'ungueltig');
});

test('the Bankleitzahl only of a valid German IBAN', () => {
    assert.equal(b.blz('DE89 3704 0044 0532 0130 00'), '37040044');
    assert.equal(b.blz('DE00123456789012345678'), null);
    assert.equal(b.blz('AT611904300234573201'), null);
});

test('BIC shape', () => {
    assert.equal(b.bicGueltig('COBADEFFXXX'), true);
    assert.equal(b.bicGueltig('coba deff'), true);
    assert.equal(b.bicGueltig('GENODEF1P12'), true);
    assert.equal(b.bicGueltig(''), false);
    assert.equal(b.bicGueltig('COBADE'), false);
    assert.equal(b.bicGueltig('COBADEFFX'), false);
    assert.equal(b.bicGueltig('COBAD1FFXXX'), false);
});

test('8 and 11 characters with XXX are the same BIC', () => {
    assert.equal(b.bicGleich('COBADEFF', 'COBADEFFXXX'), true);
    assert.equal(b.bicGleich('HYVEDEMM', 'HYVEDEMM488'), false);
});

test('the error text of the BIC field', () => {
    assert.equal(b.bicFehler('', 'COBADEFFXXX'), '');
    assert.equal(b.bicFehler('COBADEFF', 'COBADEFFXXX'), '');
    assert.equal(b.bicFehler('INGDDEFFXXX', null), '', 'bank unknown: only the shape counts');
    assert.match(b.bicFehler('COBADE', null), /richtige Form/);
    assert.match(b.bicFehler('INGDDEFFXXX', 'COBADEFFXXX'), /gehört COBADEFFXXX/);
});
