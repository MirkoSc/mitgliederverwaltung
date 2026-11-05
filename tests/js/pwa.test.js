'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// docs/spec/02-sicherheit.md section 7: the service worker keeps nothing.
// Comments stripped, so the explanation in the file may name what is ruled out.
const code = fs.readFileSync(path.join(__dirname, '../../public/sw.js'), 'utf8')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\/\/.*$/gm, '');

test('the service worker uses no cache and no storage', () => {
    for (const verboten of ['caches', 'Cache', 'indexedDB', 'localStorage', 'sessionStorage', 'importScripts']) {
        assert.equal(code.includes(verboten), false, verboten);
    }
});

test('the service worker answers no request itself', () => {
    assert.equal(code.includes('fetch'), false);
    assert.equal(code.includes('respondWith'), false);
});
