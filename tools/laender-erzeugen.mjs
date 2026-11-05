// Regenerates app/data/laender.php from langs/de.json of the npm package
// "i18n-iso-countries" (MIT). Not part of a release.
//
//   mkdir /tmp/iso && cd /tmp/iso && npm pack i18n-iso-countries && tar xzf *.tgz package/langs/de.json
//   node tools/laender-erzeugen.mjs /tmp/iso/package/langs/de.json 7.14.0 > app/data/laender.php
//
// Order of the file: Germany, the neighbours (App\Support\Laender::NACHBARN),
// then the rest sorted German (Intl.Collator), so the server needs no intl.
import { readFileSync } from 'node:fs';

const [, , datei, version = '?'] = process.argv;
const roh = JSON.parse(readFileSync(datei, 'utf8')).countries;
const NACHBARN = ['DE', 'AT', 'CH', 'FR', 'NL', 'BE', 'LU', 'DK', 'PL', 'CZ'];

const name = (wert) => (Array.isArray(wert) ? wert[0] : wert);
const sortierer = new Intl.Collator('de');
const rest = Object.keys(roh)
    .filter((code) => !NACHBARN.includes(code))
    .sort((a, b) => sortierer.compare(name(roh[a]), name(roh[b])));

const zeilen = [...NACHBARN, ...rest].map((code) => `    '${code}' => '${name(roh[code]).replaceAll("'", "\\'")}',`);

process.stdout.write(`<?php

declare(strict_types=1);

/*
 * Countries by ISO 3166-1 alpha-2 code with their German name, in the order
 * of the selection: Germany, the neighbours, then the rest alphabetically.
 * Source: npm package "i18n-iso-countries" ${version} (MIT licence,
 * (c) Michael Kaulfuss and contributors), langs/de.json.
 * Regenerate with tools/laender-erzeugen.mjs; used by App\\Support\\Laender.
 */
return [
${zeilen.join('\n')}
];
`);
