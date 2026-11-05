// Recording for app/data/hilfe/10-mitglied-loeschen.php (admin only). Deletes
// the former member 1032 (Otto Kranz), whom the sample data create for this
// guide alone; the data are created anew before the next mode.

export const zugang = 'admin';

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await seite.getByLabel('Ehemalige anzeigen').check();
    await seite.getByRole('row', { name: /\b1032\b/ }).getByRole('link').first().click();
    // The tab "Daten" is open by default; the button stands below the form.
    const loeschen = seite.getByRole('link', { name: 'Mitglied löschen', exact: true });
    await markiere(loeschen);
    await foto(4);

    await loeschen.click();
    await seite.getByRole('heading', { name: 'Mitglied löschen?', level: 1 }).waitFor();
    await markiere(seite.getByRole('link', { name: 'Weiter', exact: true }));
    await foto(5);

    await seite.getByRole('link', { name: 'Weiter', exact: true }).click();
    await seite.getByRole('heading', { name: 'Endgültig löschen?', level: 1 }).waitFor();
    await seite.getByLabel('Mitgliedsnummer zur Bestätigung eintippen').fill('1032');
    await markiere(seite.getByLabel('Mitgliedsnummer zur Bestätigung eintippen'));
    await foto(6);

    await seite.getByRole('button', { name: 'Mitglied endgültig löschen' }).click();
    await markiere(seite.getByText('Mitglied Nr. 1032 ist gelöscht.'));
    await foto(8);
}
