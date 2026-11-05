'use strict';

/**
 * Finds the installed Playwright (nothing is added to the repository, there
 * is no package.json): first the normal lookup (NODE_PATH, node_modules above
 * this directory), then the global modules directory of npm.
 */

const { execFileSync } = require('node:child_process');
const path = require('node:path');

function playwrightLaden() {
    try {
        return require('playwright');
    } catch {
        // fall through to the global directory
    }
    try {
        const global = execFileSync('npm', ['root', '-g'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
        return require(path.join(global, 'playwright'));
    } catch {
        throw new Error(
            'Playwright ist nicht installiert. Siehe tools/hilfe-screenshots/README.md (Voraussetzungen): '
            + 'npm install -g playwright && npx playwright install chromium',
        );
    }
}

module.exports = { playwrightLaden };
