// Recording for app/data/hilfe/07-austritt-eintragen.php. Member 1028 (Rudolf
// Engel) leaves; the leaving date and reason are cleared again afterwards.

async function oeffne(seite) {
    await seite.goto('/app/mitglieder');
    await seite.getByRole('row', { name: /\b1028\b/ }).getByRole('link').first().click();
    await seite.getByRole('link', { name: 'Daten', exact: true }).waitFor();
}

export default async function ablauf({ seite, markiere, foto }) {
    await oeffne(seite);
    await seite.getByLabel('Austrittsdatum').fill('2026-10-31');
    await seite.getByLabel('Austrittsgrund').fill('Umzug');
    await markiere(seite.getByLabel('Austrittsdatum'));
    await foto(3);

    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await markiere(seite.getByText('Mitglied gespeichert.'));
    await foto(6);

    // Restore: still a member. The member is "ehemalig" now, so the list shows
    // it only with "Ehemalige anzeigen".
    await seite.goto('/app/mitglieder');
    await seite.getByLabel('Ehemalige anzeigen').check();
    await seite.getByRole('row', { name: /\b1028\b/ }).getByRole('link').first().click();
    await seite.getByRole('link', { name: 'Daten', exact: true }).waitFor();
    await seite.getByLabel('Austrittsdatum').fill('');
    await seite.getByLabel('Austrittsgrund').fill('');
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await seite.getByText('Mitglied gespeichert.').waitFor();
}
