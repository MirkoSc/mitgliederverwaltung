// Recording for app/data/hilfe/06-abteilung-zuordnen.php. Member 1021 (Michael
// Frey) has no department; the tick is removed again afterwards.

async function oeffne(seite) {
    await seite.goto('/app/mitglieder');
    await seite.getByRole('row', { name: /\b1021\b/ }).getByRole('link').first().click();
    await seite.getByRole('link', { name: 'Daten', exact: true }).waitFor();
}

export default async function ablauf({ seite, markiere, foto }) {
    await oeffne(seite);
    const haken = seite.getByRole('group', { name: 'Abteilungen' }).getByLabel('Fußball');
    await haken.check();
    await markiere(seite.getByRole('group', { name: 'Abteilungen' }));
    await foto(3);

    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await markiere(seite.getByText('Mitglied gespeichert.'));
    await foto(5);

    // Restore: no department again.
    await oeffne(seite);
    await seite.getByRole('group', { name: 'Abteilungen' }).getByLabel('Fußball').uncheck();
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await seite.getByText('Mitglied gespeichert.').waitFor();
}
