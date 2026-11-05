/**
 * Recording of the guide "Neues Mitglied anlegen" (app/data/hilfe/
 * 03-mitglied-anlegen.php). Fills what the steps name, so the new member
 * looks like the others in the pictures of later recordings. The address
 * suggestions come from lib/anschrift.js (the browser answers them itself,
 * the pictures do not depend on the external service).
 */

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { anschriftVorschlaegeErsetzen } = require('../lib/anschrift.js');

export default async function ablauf({ seite, markiere, foto }) {
    await anschriftVorschlaegeErsetzen(seite);

    await seite.goto('/app/mitglieder');
    await markiere(seite.getByRole('link', { name: 'Neues Mitglied' }));
    await foto(2);

    await seite.getByRole('link', { name: 'Neues Mitglied' }).click();
    await seite.getByRole('heading', { name: 'Neues Mitglied', level: 1 }).waitFor();
    // The number is given out on saving; the form shows it as text only.
    if (await seite.getByRole('textbox', { name: 'Mitgliedsnummer' }).count() !== 0) {
        throw new Error('Die Mitgliedsnummer ist wieder ein Eingabefeld - Schritt 3 anpassen.');
    }
    const nachname = seite.getByLabel('Nachname');
    await nachname.fill('Beispiel');
    await markiere(nachname);
    await foto(3);

    await seite.getByLabel('Vorname').fill('Erika');
    await seite.getByLabel('Geburtsdatum').fill('1990-04-12');

    // Typed key by key: the suggestions answer to typing, not to fill().
    const strasse = seite.getByRole('combobox', { name: 'Straße und Hausnummer' });
    await strasse.pressSequentially('Lindenw');
    const vorschlag = seite.getByRole('option', { name: 'Lindenweg 3, 12345 Musterstadt' });
    await vorschlag.waitFor();
    await markiere(vorschlag);
    await foto(5);

    await vorschlag.click();
    for (const [feld, wert] of [['PLZ', '12345'], ['Ort', 'Musterstadt'], ['Land', 'Deutschland']]) {
        const inhalt = await seite.getByLabel(feld, { exact: true }).inputValue();
        if (inhalt !== wert) {
            throw new Error(`Der Vorschlag hat ${feld} nicht gefüllt ("${inhalt}").`);
        }
    }

    // Step 7: a German IBAN brings BIC and bank by itself.
    await seite.getByLabel('IBAN').fill('DE89370400440532013000');
    await seite.getByLabel('IBAN').blur();
    await seite.getByText('Bank: Commerzbank').waitFor();
    if (await seite.getByLabel('BIC').inputValue() !== 'COBADEFFXXX') {
        throw new Error('Die BIC wurde nicht ergänzt.');
    }

    await seite.getByRole('button', { name: 'Mitglied anlegen' }).click();
    await seite.getByText('Mitglied angelegt.').waitFor();
    await markiere(seite.getByText('Mitglied angelegt.'));
    await foto(9);

    // Not deleted again: the role Vorstand may not delete members. The new
    // member stays until the sample data are created anew (between the modes).
}
