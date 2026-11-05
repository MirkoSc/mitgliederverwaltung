/**
 * Recording of the guide "Neues Mitglied anlegen" (app/data/hilfe/
 * 03-mitglied-anlegen.php). Creates member 9001 (no other recording uses
 * that number).
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

    // Not deleted again: the role Vorstand may not delete members. Member 9001
    // stays until the sample data are created anew (between the modes).
}
