#!/usr/bin/env node
'use strict';

/**
 * Takes the pictures of the help guides (docs/spec/12-hilfe.md section 6).
 * Called by start.sh, which has started the docker project; do not run it
 * against an installation with real data - it recreates the sample data.
 *
 * For each mode (normal, then barrierefrei): create the sample data anew,
 * log in as the demo account of that mode, run every recording
 * anleitungen/<slug>.mjs and save what it photographs as
 * <ausgabe>/<slug>/<NN>-<modus>.png. With --pruefen nothing is written.
 */

const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const { WURZEL, appPort, php } = require('./lib/docker');
const { MODI, bildPfad, kontoSchluessel } = require('./lib/namen');
const { abgleich, anleitungenLesen, dateienAbgleichen } = require('./lib/abgleich');
const { appCode, sekundenBisNaechsterSchritt } = require('./lib/totp');
const { optionenLesen } = require('./lib/optionen');
const { playwrightLaden } = require('./lib/playwright');

const HIER = __dirname;
const AUFNAHMEN = path.join(HIER, 'anleitungen');
const PROBE_AUSGABE = path.join(HIER, 'probe-ausgabe');
const STANDARD_AUSGABE = path.join(WURZEL, 'public', 'img', 'hilfe');

/** Limits of 12 section 6: every picture, and all together (they go into each release ZIP). */
const BILD_MAX_BYTES = 250 * 1024;
const GESAMT_MAX_BYTES = 10 * 1024 * 1024;

/** Added to every page in the browser of this script only (bypassCSP). */
const INIT_SKRIPT = () => {
    const stil = '*,*::before,*::after{animation:none!important;transition:none!important;'
        + 'scroll-behavior:auto!important;caret-color:transparent!important}'
        + '[data-hilfe-markiert]{outline:4px solid #e0004d!important;outline-offset:3px!important;'
        + 'box-shadow:0 0 0 8px rgba(224,0,77,.28)!important}';
    const einfuegen = () => {
        const element = document.createElement('style');
        element.textContent = stil;
        document.head.appendChild(element);
    };
    if (document.head) {
        einfuegen();
    } else {
        document.addEventListener('DOMContentLoaded', einfuegen);
    }
};

function warte(ms) {
    return new Promise((fertig) => setTimeout(fertig, ms));
}

/** The first lines of an error: Playwright appends a long call log. */
function kurz(fehler) {
    // eslint-disable-next-line no-control-regex
    return String(fehler?.message ?? fehler).replace(/\u001b\[[0-9;]*m/g, '').split('\n').filter((z) => z.trim() !== '').slice(0, 3).join(' | ');
}

/** Path without query: no search words in messages. */
function ohneQuery(adresse) {
    try {
        return new URL(adresse).pathname;
    } catch {
        return adresse;
    }
}

async function aufnahmenLaden() {
    const aufnahmen = new Map();
    for (const datei of fs.readdirSync(AUFNAHMEN).filter((name) => name.endsWith('.mjs')).sort()) {
        const slug = datei.slice(0, -'.mjs'.length);
        const modul = await import(pathToFileURL(path.join(AUFNAHMEN, datei)).href);
        if (typeof modul.default !== 'function') {
            throw new Error(`anleitungen/${datei} muss eine Funktion als default exportieren.`);
        }
        aufnahmen.set(slug, { slug, ablauf: modul.default, zugang: modul.zugang ?? null, probe: modul.probe === true });
    }
    return aufnahmen;
}

function anleitungenHolen(optionen) {
    if (optionen.anleitungenJson) {
        return fs.readFileSync(optionen.anleitungenJson, 'utf8');
    }
    if (!fs.existsSync(path.join(WURZEL, 'tools', 'hilfe-anleitungen.php'))) {
        throw new Error('tools/hilfe-anleitungen.php fehlt (kommt mit dem Gerüst der Hilfe, #114).');
    }
    return php(optionen.projekt, 'tools/hilfe-anleitungen.php');
}

async function aufAnwendungWarten(basis) {
    const ende = Date.now() + 90_000;
    let letzter = '';
    while (Date.now() < ende) {
        try {
            const antwort = await fetch(`${basis}/anmelden`);
            if (antwort.ok) {
                return;
            }
            letzter = `HTTP ${antwort.status}`;
        } catch (fehler) {
            letzter = kurz(fehler);
        }
        await warte(1000);
    }
    throw new Error(`Die Anwendung unter ${basis} antwortet nicht (${letzter}).`);
}

async function kontextOeffnen(browser, basis) {
    const kontext = await browser.newContext({
        baseURL: basis,
        viewport: { width: 1280, height: 800 },
        deviceScaleFactor: 1,
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        colorScheme: 'light',
        reducedMotion: 'reduce',
        bypassCSP: true,
    });
    await kontext.addInitScript(INIT_SKRIPT);

    return kontext;
}

/** Login with e-mail, password and the code of the authenticator app. */
async function anmelden(kontext, konto, passwort, modus) {
    const seite = await kontext.newPage();
    seite.setDefaultTimeout(15_000);
    await seite.goto('/anmelden');
    await seite.getByLabel('E-Mail-Adresse').fill(konto.email);
    await seite.getByLabel('Passwort', { exact: true }).fill(passwort);
    await seite.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await seite.waitForURL('**/anmelden/bestaetigen');

    let angemeldet = false;
    for (let versuch = 0; versuch < 3 && !angemeldet; versuch++) {
        await seite.getByLabel('Code').fill(appCode(konto.totp_geheimnis));
        await seite.getByRole('button', { name: 'Anmelden', exact: true }).click();
        await seite.waitForLoadState('load');
        angemeldet = !new URL(seite.url()).pathname.startsWith('/anmelden');
        if (!angemeldet) {
            // A step counts once; wait for the next one.
            await warte(sekundenBisNaechsterSchritt() * 1000 + 1500);
            await seite.goto('/anmelden/bestaetigen');
        }
    }
    if (!angemeldet) {
        throw new Error(`Anmeldung von ${konto.email} fehlgeschlagen.`);
    }
    const barrierefrei = await seite.evaluate(() => document.documentElement.classList.contains('modus-barrierefrei'));
    if (barrierefrei !== (modus === 'barrierefrei')) {
        throw new Error(`Das Konto ${konto.email} steht nicht im Modus "${modus}".`);
    }
    await seite.close();
}

/**
 * One recording in one mode.
 *
 * @returns {{probleme: string[], bilder: Map<number, Buffer>}}
 */
async function ablaufAusfuehren({ kontext, aufnahme, erwartet, pruefen }) {
    const probleme = [];
    const bilder = new Map();
    const nummern = [];
    const seite = await kontext.newPage();
    seite.setDefaultTimeout(pruefen ? 5000 : 10_000);

    seite.on('console', (meldung) => {
        if (meldung.type() === 'error') {
            probleme.push(`Fehler in der Browser-Konsole: ${meldung.text().slice(0, 200)}`);
        }
    });
    seite.on('pageerror', (fehler) => probleme.push(`Skriptfehler im Browser: ${kurz(fehler)}`));
    seite.on('response', (antwort) => {
        if (antwort.status() >= 400) {
            probleme.push(`HTTP ${antwort.status()} für ${ohneQuery(antwort.url())}`);
        }
    });

    const markiere = async (element) => {
        await element.scrollIntoViewIfNeeded();
        await element.evaluate((knoten) => knoten.setAttribute('data-hilfe-markiert', ''));
    };

    const foto = async (nr) => {
        nummern.push(nr);
        if (pruefen) {
            // Nothing is written; the marked element had to exist, that is the check.
        } else if (!bilder.has(nr)) {
            await seite.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => undefined);
            bilder.set(nr, await seite.screenshot({ type: 'png', animations: 'disabled', caret: 'hide' }));
        }
        await seite.evaluate(() => document.querySelectorAll('[data-hilfe-markiert]')
            .forEach((knoten) => knoten.removeAttribute('data-hilfe-markiert')));
    };

    try {
        await aufnahme.ablauf({ seite, markiere, foto });
    } catch (fehler) {
        probleme.push(kurz(fehler));
    }
    await seite.close();

    if (erwartet !== undefined) {
        const ergebnis = abgleich(erwartet, nummern);
        if (!ergebnis.ok) {
            probleme.push(`Abgleich mit der Anleitung: ${ergebnis.meldung}`);
        }
    }

    return { probleme: [...new Set(probleme)], bilder };
}

async function main() {
    const optionen = optionenLesen(process.argv.slice(2));
    const aufnahmen = await aufnahmenLaden();
    const auswahl = optionen.nur ? [optionen.nur] : [...aufnahmen.keys()];
    for (const slug of auswahl) {
        if (!aufnahmen.has(slug)) {
            throw new Error(`Es gibt keine Aufnahme anleitungen/${slug}.mjs.`);
        }
    }
    const proben = [...aufnahmen.values()].filter((a) => a.probe).map((a) => a.slug);

    // The guides (same data as the application) - not needed for probes alone.
    let anleitungen = new Map();
    if (auswahl.some((slug) => !proben.includes(slug)) || !optionen.nur) {
        anleitungen = anleitungenLesen(anleitungenHolen(optionen));
    }
    const geprueft = optionen.nur
        ? new Map([...anleitungen].filter(([slug]) => slug === optionen.nur))
        : anleitungen;
    const dateiProbleme = dateienAbgleichen(geprueft, auswahl, proben);
    if (dateiProbleme.length > 0) {
        throw new Error(dateiProbleme.join('\n'));
    }

    const konten = JSON.parse(fs.readFileSync(path.join(HIER, 'konten.json'), 'utf8'));
    const port = appPort(optionen.projekt);
    const basis = `http://localhost:${port}`;
    await aufAnwendungWarten(basis);
    console.log(`Anwendung: ${basis} (Projekt ${optionen.projekt})${optionen.pruefen ? ', nur prüfen' : ''}`);

    const { chromium } = playwrightLaden();
    const browser = await chromium.launch();
    const fehler = [];
    const geschrieben = [];

    try {
        for (const modus of MODI) {
            console.log(`\nModus ${modus}: Beispieldaten anlegen`);
            console.log(php(optionen.projekt, 'tools/hilfe-beispieldaten.php').trim());
            const kontexte = new Map();

            for (const slug of auswahl) {
                const aufnahme = aufnahmen.get(slug);
                const schluessel = kontoSchluessel(aufnahme.zugang, modus);
                const konto = konten.konten[schluessel];
                if (!konto) {
                    fehler.push(`${slug} (${modus}): kein Konto "${schluessel}" in konten.json`);
                    continue;
                }
                if (!kontexte.has(schluessel)) {
                    const kontext = await kontextOeffnen(browser, basis);
                    await anmelden(kontext, konto, konten.passwort, modus);
                    kontexte.set(schluessel, kontext);
                }

                const erwartet = anleitungen.get(slug)?.bilder;
                const ergebnis = await ablaufAusfuehren({
                    kontext: kontexte.get(schluessel),
                    aufnahme,
                    erwartet,
                    pruefen: optionen.pruefen,
                });
                if (ergebnis.probleme.length > 0) {
                    console.log(`  FEHLER ${slug}`);
                    for (const problem of ergebnis.probleme) {
                        fehler.push(`${slug} (${modus}): ${problem}`);
                    }
                    continue;
                }
                console.log(`  ok     ${slug}${optionen.pruefen ? '' : ` (${ergebnis.bilder.size} Bild(er))`}`);

                const ausgabe = optionen.ausgabe ?? (aufnahme.probe ? PROBE_AUSGABE : STANDARD_AUSGABE);
                for (const [nr, daten] of ergebnis.bilder) {
                    const ziel = bildPfad(ausgabe, slug, nr, modus);
                    if (daten.length > BILD_MAX_BYTES) {
                        fehler.push(`${slug} (${modus}): Bild ${nr} ist ${Math.round(daten.length / 1024)} KB groß (höchstens ${BILD_MAX_BYTES / 1024} KB)`);
                    }
                    fs.mkdirSync(path.dirname(ziel), { recursive: true });
                    fs.writeFileSync(ziel, daten);
                    geschrieben.push({ ziel, groesse: daten.length });
                }
            }
            await Promise.all([...kontexte.values()].map((kontext) => kontext.close()));
        }
    } finally {
        await browser.close();
    }

    const gesamt = geschrieben.reduce((summe, bild) => summe + bild.groesse, 0);
    if (gesamt > GESAMT_MAX_BYTES) {
        fehler.push(`Alle Bilder zusammen sind ${(gesamt / 1024 / 1024).toFixed(1)} MB groß (höchstens ${GESAMT_MAX_BYTES / 1024 / 1024} MB)`);
    }
    if (!optionen.pruefen) {
        console.log(`\n${geschrieben.length} Bild(er) geschrieben, ${Math.round(gesamt / 1024)} KB.`);
    }
    if (fehler.length > 0) {
        console.error(`\n${fehler.length} Problem(e):`);
        for (const eintrag of fehler) {
            console.error(`  - ${eintrag}`);
        }
        process.exitCode = 1;
    } else {
        console.log('\nAlles in Ordnung.');
    }
}

main().catch((fehler) => {
    console.error(`\nAbbruch: ${kurz(fehler)}`);
    process.exit(1);
});
