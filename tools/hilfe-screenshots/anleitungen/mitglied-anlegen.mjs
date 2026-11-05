/**
 * Recording of the guide "Neues Mitglied anlegen" (app/data/hilfe/
 * 03-mitglied-anlegen.php). Keeps the number the form suggests (step 3 says
 * it is already there) and fills what step 5 names, so the new member looks
 * like the others in the pictures of later recordings.
 */

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await markiere(seite.getByRole('link', { name: 'Neues Mitglied' }));
    await foto(2);

    await seite.getByRole('link', { name: 'Neues Mitglied' }).click();
    await seite.getByRole('heading', { name: 'Neues Mitglied', level: 1 }).waitFor();
    const nummer = seite.getByLabel('Mitgliedsnummer');
    if (!/^\d+$/.test(await nummer.inputValue())) {
        throw new Error('Die Mitgliedsnummer ist nicht vorbelegt.');
    }
    await markiere(nummer);
    await foto(3);

    await seite.getByLabel('Nachname').fill('Beispiel');
    await seite.getByLabel('Vorname').fill('Erika');
    await seite.getByLabel('Geburtsdatum').fill('1990-04-12');
    await seite.getByLabel('Straße und Hausnummer').fill('Lindenweg 3');
    await seite.getByLabel('PLZ').fill('12345');
    await seite.getByLabel('Ort', { exact: true }).fill('Musterstadt');
    await markiere(seite.getByRole('button', { name: 'Mitglied anlegen' }));
    await foto(6);

    await seite.getByRole('button', { name: 'Mitglied anlegen' }).click();
    await seite.getByText('Mitglied angelegt.').waitFor();
    await markiere(seite.getByText('Mitglied angelegt.'));
    await foto(7);

    // Not deleted again: the role Vorstand may not delete members. The new
    // member stays until the sample data are created anew (between the modes).
}
