'use strict';

// Pure logic of the help screenshot script (docs/spec/12-hilfe.md sections 6
// and 7, issue #115): authenticator code, picture names, reconciliation of
// guides and recordings, command line. The browser part is checked by
// `tools/hilfe-screenshots/start.sh --pruefen`.

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const lib = path.join(__dirname, '..', '..', 'tools', 'hilfe-screenshots', 'lib');
const { base32Decode, hotp, totp, appCode, sekundenBisNaechsterSchritt } = require(path.join(lib, 'totp.js'));
const { bildDatei, bildPfad, gueltigerSlug, kontoSchluessel, MODI } = require(path.join(lib, 'namen.js'));
const { abgleich, anleitungenLesen, dateienAbgleichen } = require(path.join(lib, 'abgleich.js'));
const { optionenLesen } = require(path.join(lib, 'optionen.js'));
const { composeArgs, portLesen } = require(path.join(lib, 'docker.js'));

// ---------------------------------------------------------------- TOTP

test('HOTP: RFC 4226 appendix D test vectors', () => {
    const geheimnis = Buffer.from('12345678901234567890');
    const erwartet = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
    erwartet.forEach((code, zaehler) => assert.equal(hotp(geheimnis, zaehler), code));
});

test('TOTP: RFC 6238 appendix B test vectors (SHA-1, SHA-256, SHA-512, 8 digits)', () => {
    const geheimnisse = {
        sha1: Buffer.from('12345678901234567890'),
        sha256: Buffer.from('12345678901234567890123456789012'),
        sha512: Buffer.from('1234567890123456789012345678901234567890123456789012345678901234'),
    };
    const vektoren = [
        [59, '94287082', '46119246', '90693936'],
        [1111111109, '07081804', '68084774', '25091201'],
        [1111111111, '14050471', '67062674', '99943326'],
        [1234567890, '89005924', '91819424', '93441116'],
        [2000000000, '69279037', '90698825', '38618901'],
        [20000000000, '65353130', '77737706', '47863826'],
    ];
    for (const [zeit, sha1, sha256, sha512] of vektoren) {
        assert.equal(totp(geheimnisse.sha1, zeit, { stellen: 8 }), sha1, `sha1 ${zeit}`);
        assert.equal(totp(geheimnisse.sha256, zeit, { stellen: 8, algorithmus: 'sha256' }), sha256, `sha256 ${zeit}`);
        assert.equal(totp(geheimnisse.sha512, zeit, { stellen: 8, algorithmus: 'sha512' }), sha512, `sha512 ${zeit}`);
    }
});

test('Base32: decodes like the authenticator apps and ignores case, spaces and padding', () => {
    assert.equal(base32Decode('MFRGGZDFMY======').toString(), 'abcdef');
    assert.equal(base32Decode('mfrg gzdf my').toString(), 'abcdef');
    assert.deepEqual(base32Decode(''), Buffer.alloc(0));
});

test('appCode: six digits, the code of the RFC secret in Base32', () => {
    // "12345678901234567890" in Base32; time 59 s -> the 8-digit vector 94287082 ends in 287082.
    const base32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    assert.equal(appCode(base32, 59_000), '287082');
    assert.match(appCode(base32), /^\d{6}$/);
});

test('seconds until the next 30-second step', () => {
    assert.equal(sekundenBisNaechsterSchritt(0), 30);
    assert.equal(sekundenBisNaechsterSchritt(29_999), 1);
    assert.equal(sekundenBisNaechsterSchritt(30_000), 30);
    assert.equal(sekundenBisNaechsterSchritt(45_500), 15);
});

// -------------------------------------------------------------- Namen

test('picture names: two digits, mode, .png (12 section 2)', () => {
    assert.equal(bildDatei(1, 'normal'), '01-normal.png');
    assert.equal(bildDatei(12, 'barrierefrei'), '12-barrierefrei.png');
    assert.equal(bildPfad('public/img/hilfe', 'anmelden', 3, 'normal'), path.join('public/img/hilfe', 'anmelden', '03-normal.png'));
});

test('picture names refuse a wrong step number, mode or slug', () => {
    assert.throws(() => bildDatei(0, 'normal'), /ungültig/);
    assert.throws(() => bildDatei(1.5, 'normal'), /ungültig/);
    assert.throws(() => bildDatei(100, 'normal'), /ungültig/);
    assert.throws(() => bildDatei(1, 'dunkel'), /Modus/);
    assert.throws(() => bildPfad('x', '../etc', 1, 'normal'), /Slug/);
    assert.throws(() => bildPfad('x', 'Gross', 1, 'normal'), /Slug/);
});

test('slugs are [a-z0-9-]+ only', () => {
    assert.ok(gueltigerSlug('mitglied-anlegen'));
    assert.ok(gueltigerSlug('08-x'));
    for (const schlecht of ['', 'A', 'a b', 'a_b', 'a/b', 'ä', null, undefined, 5]) {
        assert.equal(gueltigerSlug(schlecht), false, String(schlecht));
    }
});

test('demo account key: by mode, or by access and mode', () => {
    assert.equal(kontoSchluessel(null, 'normal'), 'normal');
    assert.equal(kontoSchluessel(undefined, 'barrierefrei'), 'barrierefrei');
    assert.equal(kontoSchluessel('admin', 'normal'), 'admin-normal');
    assert.throws(() => kontoSchluessel(null, 'x'), /Modus/);
});

test('konten.json: both modes, valid secrets, the right mode flags, role Vorstand', () => {
    const datei = path.join(__dirname, '..', '..', 'tools', 'hilfe-screenshots', 'konten.json');
    const konten = JSON.parse(fs.readFileSync(datei, 'utf8'));
    assert.ok(konten.passwort.length >= 12);
    for (const modus of MODI) {
        const konto = konten.konten[modus];
        assert.ok(konto, modus);
        assert.match(konto.email, /^hilfe-[a-z]+@example\.org$/);
        assert.equal(konto.barrierefrei, modus === 'barrierefrei');
        assert.equal(konto.rolle, 'vorstand');
        assert.equal(base32Decode(konto.totp_geheimnis).length, 20, 'a 160-bit secret like the app generates');
    }
    assert.notEqual(konten.konten.normal.totp_geheimnis, konten.konten.barrierefrei.totp_geheimnis);
});

// ------------------------------------------------------------ Abgleich

test('reconciliation: the photographed steps are exactly the steps with a picture', () => {
    assert.deepEqual(abgleich([1, 3], [1, 3]).ok, true);
    assert.deepEqual(abgleich([1, 3], [3, 1]).ok, true, 'order does not matter');
    assert.deepEqual(abgleich([], []).ok, true);

    const fehlt = abgleich([1, 3], [1]);
    assert.equal(fehlt.ok, false);
    assert.deepEqual(fehlt.fehlend, [3]);

    const zuviel = abgleich([1], [1, 2]);
    assert.equal(zuviel.ok, false);
    assert.deepEqual(zuviel.uebrig, [2]);

    const doppelt = abgleich([1], [1, 1]);
    assert.equal(doppelt.ok, false);
    assert.deepEqual(doppelt.doppelt, [1]);
    assert.match(doppelt.meldung, /mehrfach/);
});

test('reading the guides: JSON of tools/hilfe-anleitungen.php', () => {
    const karte = anleitungenLesen(JSON.stringify([
        { slug: 'anmelden', titel: 'Anmelden und abmelden', recht: null, bilder: [3, 1] },
        { slug: 'mitglied-suchen', titel: 'Mitglied suchen', recht: 'MitgliederSehen', bilder: [] },
    ]));
    assert.deepEqual([...karte.keys()], ['anmelden', 'mitglied-suchen']);
    assert.deepEqual(karte.get('anmelden').bilder, [1, 3], 'sorted');
    assert.equal(karte.get('mitglied-suchen').recht, 'MitgliederSehen');
});

test('reading the guides refuses wrong data', () => {
    assert.throws(() => anleitungenLesen('kein json'), /JSON/);
    assert.throws(() => anleitungenLesen('{}'), /Liste/);
    assert.throws(() => anleitungenLesen('[{"slug":"A","bilder":[]}]'), /slug/);
    assert.throws(() => anleitungenLesen('[{"slug":"a"}]'), /bilder/);
    assert.throws(() => anleitungenLesen('[{"slug":"a","bilder":[0]}]'), /bilder/);
    assert.throws(() => anleitungenLesen('[{"slug":"a","bilder":[]},{"slug":"a","bilder":[]}]'), /doppelt/);
});

test('files: a guide with pictures needs a recording, a recording needs a guide (probes excepted)', () => {
    const karte = anleitungenLesen(JSON.stringify([
        { slug: 'anmelden', titel: 'A', recht: null, bilder: [1] },
        { slug: 'nur-text', titel: 'B', recht: null, bilder: [] },
    ]));
    assert.deepEqual(dateienAbgleichen(karte, ['anmelden']), []);
    assert.deepEqual(dateienAbgleichen(karte, ['anmelden', 'beispiel'], ['beispiel']), []);

    const ohneAufnahme = dateienAbgleichen(karte, []);
    assert.equal(ohneAufnahme.length, 1);
    assert.match(ohneAufnahme[0], /anmelden.*keine Aufnahme/);

    const ohneAnleitung = dateienAbgleichen(karte, ['anmelden', 'verwaist']);
    assert.equal(ohneAnleitung.length, 1);
    assert.match(ohneAnleitung[0], /verwaist.*keine Anleitung/);
});

test('the example recording is a probe and belongs to no guide', () => {
    const datei = path.join(__dirname, '..', '..', 'tools', 'hilfe-screenshots', 'anleitungen', 'beispiel.mjs');
    assert.match(fs.readFileSync(datei, 'utf8'), /export const probe = true/);
});

// ------------------------------------------------------- Kommandozeile

test('command line: defaults and all options', () => {
    assert.deepEqual(optionenLesen([]), { projekt: 'hilfe', pruefen: false, nur: null, ausgabe: null, anleitungenJson: null });
    const optionen = optionenLesen(['--pruefen', '--projekt', 'issue-115', '--nur', 'anmelden', '--ausgabe', '/tmp/x', '--anleitungen-json', 'a.json']);
    assert.deepEqual(optionen, { projekt: 'issue-115', pruefen: true, nur: 'anmelden', ausgabe: '/tmp/x', anleitungenJson: 'a.json' });
});

test('command line: refuses unknown options, missing values and bad slugs', () => {
    assert.throws(() => optionenLesen(['--schnell']), /Unbekannte Option/);
    assert.throws(() => optionenLesen(['--nur']), /braucht einen Wert/);
    assert.throws(() => optionenLesen(['--nur', '--pruefen']), /braucht einen Wert/);
    assert.throws(() => optionenLesen(['--nur', '../x']), /Slug/);
});

// -------------------------------------------------------------- Docker

test('docker: an own project and no fixed host port (05 section 6)', () => {
    const args = composeArgs('issue-115');
    assert.deepEqual(args.slice(0, 3), ['compose', '-p', 'issue-115']);
    assert.ok(args.some((a) => a.endsWith('compose.override.yml')));
    assert.throws(() => composeArgs('Gross Name'), /Projektname/);

    const override = fs.readFileSync(path.join(lib, '..', 'compose.override.yml'), 'utf8');
    assert.match(override, /!override/);
    const ohneKommentare = override.split('\n').filter((zeile) => !zeile.trim().startsWith('#')).join('\n');
    assert.doesNotMatch(ohneKommentare, /8080/);
});

test('docker: reads the port Docker chose', () => {
    assert.equal(portLesen('0.0.0.0:49153\n'), 49153);
    assert.equal(portLesen('0.0.0.0:49153\n[::]:49153\n'), 49153);
    assert.throws(() => portLesen(''), /nicht lesbar/);
});
