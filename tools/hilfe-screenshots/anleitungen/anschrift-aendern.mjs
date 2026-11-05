/**
 * Recording of the guide "Anschrift, Telefon oder E-Mail ändern"
 * (app/data/hilfe/04-anschrift-aendern.php). Changes the address of member
 * 1001 through a suggestion and puts the old values back at the end. The
 * suggestions come from lib/anschrift.js (the browser answers them itself,
 * the pictures do not depend on the external service).
 */

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { anschriftVorschlaegeErsetzen } = require('../lib/anschrift.js');

const FELDER = ['PLZ', 'Ort', 'Land'];

export default async function ablauf({ seite, markiere, foto }) {
    await anschriftVorschlaegeErsetzen(seite);

    await seite.goto('/app/mitglieder');
    await seite.getByLabel('Mitglied suchen').fill('1001');
    await seite.getByRole('row', { name: /\b1001\b/ }).getByRole('link').first().click();
    await markiere(seite.getByRole('link', { name: 'Daten', exact: true }));
    await foto(2);

    // The member form; the extended search on the same page has a PLZ, too.
    const formular = seite.locator('#mitglied-formular');
    const strasse = formular.getByRole('combobox', { name: 'Straße und Hausnummer' });
    const alt = { strasse: await strasse.inputValue() };
    for (const feld of FELDER) {
        alt[feld] = await formular.getByLabel(feld, { exact: true }).inputValue();
    }

    // Typed key by key: the suggestions answer to typing, not to fill().
    await strasse.fill('');
    await strasse.pressSequentially('Lindenw');
    const vorschlag = seite.getByRole('option', { name: 'Lindenweg 3, 12345 Musterstadt' });
    await vorschlag.waitFor();
    await markiere(vorschlag);
    await foto(3);

    await vorschlag.click();
    if (await strasse.inputValue() !== 'Lindenweg 3') {
        throw new Error('Der Vorschlag hat die Straße nicht übernommen.');
    }
    
    await markiere(seite.getByRole('button', { name: 'Mitglied speichern' }));
    await foto(6);
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await seite.getByText('Mitglied gespeichert.').waitFor();

    // Back to the sample data.
    await strasse.fill(alt.strasse);
    for (const feld of FELDER) {
        await formular.getByLabel(feld, { exact: true }).fill(alt[feld]);
    }
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await seite.getByText('Mitglied gespeichert.').waitFor();
}
