// The settings page loads several scripts side by side (import.js,
// abgleich.js, backups.js, update.js). Classic scripts share one global
// scope: a top-level "const" declared twice stops the later file with a
// SyntaxError, and a top-level function of the same name silently replaces
// the other file's one. Issue #36 found exactly that on /admin. Every own
// script therefore keeps its declarations in a function scope - this test
// runs them all in ONE context, the way a browser does, and checks that
// nothing clashes and nothing but the deliberate library leaks.
'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const verzeichnis = path.join(__dirname, '..', '..', 'public', 'js');
const dateien = fs
    .readdirSync(verzeichnis)
    .filter((name) => name.endsWith('.js'))
    .sort();

// upload.js is a small library other scripts may call (dateiHochladen);
// its helpers are global on purpose and appear in no other file.
const ERLAUBT_GLOBAL = new Set(['upload.js']);

test('all own scripts run side by side in one global scope', () => {
    // Just enough of a page for the init code: it finds none of its
    // elements and leaves again.
    const dokument = {
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: () => null,
        addEventListener: () => {},
        body: { dataset: {}, getAttribute: () => null },
        documentElement: { classList: { add: () => {}, remove: () => {} } },
        readyState: 'complete',
    };
    const kontext = vm.createContext({ document: dokument, addEventListener: () => {}, setTimeout, clearTimeout });
    kontext.window = kontext;
    const vorher = new Set(Object.getOwnPropertyNames(kontext));
    for (const name of dateien) {
        const code = fs.readFileSync(path.join(verzeichnis, name), 'utf8');
        assert.doesNotThrow(() => vm.runInContext(code, kontext, { filename: name }), name);
        if (!ERLAUBT_GLOBAL.has(name)) {
            const neu = Object.getOwnPropertyNames(kontext).filter((n) => !vorher.has(n));
            assert.deepStrictEqual(neu, [], name + ' declares globals: ' + neu.join(', '));
        }
        Object.getOwnPropertyNames(kontext).forEach((n) => vorher.add(n));
    }
});
