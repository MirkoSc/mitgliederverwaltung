/**
 * Recording of the guide "Neues Mitglied anlegen" (app/data/hilfe/
 * 03-mitglied-anlegen.php). Creates member 9001 and deletes it again, so
 * later recordings find the sample data as before.
 */

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await markiere(seite.getByRole('link', { name: 'Neues Mitglied' }));
    await foto(2);

    await seite.getByRole('link', { name: 'Neues Mitglied' }).click();
    await seite.getByRole('heading', { name: 'Neues Mitglied', level: 1 }).waitFor();
    await markiere(seite.getByLabel('Mitgliedsnummer'));
    await foto(3);

    await seite.getByLabel('Mitgliedsnummer').fill('9001');
    await seite.getByLabel('Nachname').fill('Beispiel');
    await seite.getByLabel('Vorname').fill('Erika');
    await markiere(seite.getByRole('button', { name: 'Mitglied anlegen' }));
    await foto(6);

    await seite.getByRole('button', { name: 'Mitglied anlegen' }).click();
    await seite.getByText('Mitglied angelegt.').waitFor();
    await markiere(seite.getByText('Mitglied angelegt.'));
    await foto(7);

    // Back to the sample data: delete the new member.
    await seite.getByRole('link', { name: 'Daten', exact: true }).waitFor();
    await seite.getByRole('link', { name: 'Mitglied löschen' }).click();
    await seite.getByRole('button', { name: 'Mitglied endgültig löschen' }).click();
    await seite.getByRole('heading', { name: 'Mitglieder', level: 1 }).waitFor();
}
