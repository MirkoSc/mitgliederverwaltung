'use strict';

/**
 * Thin wrapper around `docker compose` for one project of its own
 * (docs/spec/05-entwicklung.md section 6): no fixed host port, the project
 * name keeps parallel runs apart.
 */

const { execFileSync } = require('node:child_process');
const path = require('node:path');

const WURZEL = path.resolve(__dirname, '..', '..', '..');
const OVERRIDE = path.join(WURZEL, 'tools', 'hilfe-screenshots', 'compose.override.yml');

function composeArgs(projekt) {
    if (!/^[a-z0-9][a-z0-9_-]*$/.test(projekt)) {
        throw new Error(`Ungültiger Projektname "${projekt}" (a-z, 0-9, _ und -).`);
    }
    return ['compose', '-p', projekt, '-f', path.join(WURZEL, 'docker-compose.yml'), '-f', OVERRIDE];
}

function compose(projekt, args, optionen = {}) {
    return execFileSync('docker', [...composeArgs(projekt), ...args], {
        cwd: WURZEL,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'inherit'],
        ...optionen,
    });
}

/** The host port Docker chose for port 80 of the app ("0.0.0.0:49153"). */
function portLesen(ausgabe) {
    const treffer = /:(\d+)\s*$/m.exec(ausgabe);
    if (!treffer) {
        throw new Error(`Der Host-Port der Anwendung ist nicht lesbar: "${ausgabe.trim()}"`);
    }
    return Number(treffer[1]);
}

function appPort(projekt) {
    return portLesen(compose(projekt, ['port', 'app', '80']));
}

/** Runs a PHP script of the release inside the app container. */
function php(projekt, skript, args = []) {
    return compose(projekt, ['exec', '-T', 'app', 'php', skript, ...args]);
}

module.exports = { WURZEL, composeArgs, compose, portLesen, appPort, php };
