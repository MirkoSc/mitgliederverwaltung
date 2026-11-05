/**
 * Recording for app/data/hilfe/01-anmelden.php. The recording is already
 * logged in, so it drops the session cookie, shows the login (steps 1 and 4)
 * and logs in again with the code of the authenticator app. The account is
 * logged in at the end, as the other recordings expect.
 */

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { appCode, sekundenBisNaechsterSchritt } = require('../lib/totp.js');
const konten = require('../konten.json');

const warte = (ms) => new Promise((fertig) => setTimeout(fertig, ms));

export default async function ablauf({ seite, markiere, foto }) {
    // Who is logged in: the mode shows in the class of the html element.
    const barrierefrei = await seite.goto('/app').then(() => seite
        .evaluate(() => document.documentElement.classList.contains('modus-barrierefrei')));
    const konto = konten.konten[barrierefrei ? 'barrierefrei' : 'normal'];

    await seite.context().clearCookies();
    await seite.goto('/anmelden');
    await seite.getByRole('heading', { name: 'Anmelden', level: 1 }).waitFor();
    await markiere(seite.getByLabel('E-Mail-Adresse'));
    await foto(1);

    await seite.getByLabel('E-Mail-Adresse').fill(konto.email);
    await seite.getByLabel('Passwort', { exact: true }).fill(konten.passwort);
    await seite.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await seite.getByRole('heading', { name: 'Anmeldung bestätigen', level: 1 }).waitFor();
    await seite.getByText('Dieses Gerät 30 Tage merken').waitFor();
    await seite.getByRole('button', { name: 'Abbrechen' }).waitFor();
    await markiere(seite.getByLabel('Code'));
    await foto(4);

    // A code counts once per step, and the recording logged in a moment ago.
    await warte(sekundenBisNaechsterSchritt() * 1000 + 1500);
    await seite.getByLabel('Code').fill(appCode(konto.totp_geheimnis));
    await seite.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await seite.getByRole('heading', { name: 'Mitglieder', level: 1 }).waitFor();

    await seite.locator('details.benutzermenue > summary').click();
    await markiere(seite.getByRole('button', { name: 'Abmelden', exact: true }));
    await foto(9);
}
