// Recording for app/data/hilfe/05-beitragsart-aendern.php. Member 1019 (Sandra
// Roth) gets another fee type; the original state is restored afterwards.

async function oeffne(seite) {
    await seite.goto('/app/mitglieder');
    await seite.getByRole('row', { name: /\b1019\b/ }).getByRole('link').first().click();
    await seite.getByRole('link', { name: 'Daten', exact: true }).waitFor();
}

export default async function ablauf({ seite, markiere, foto }) {
    await oeffne(seite);
    const art = seite.locator('#mitglied-formular').getByLabel('Beitragsart', { exact: true });
    const alt = await art.inputValue();
    await markiere(seite.getByRole('heading', { name: 'Beitrag', level: 3 }));
    await foto(2);

    await art.selectOption(await art.locator('option', { hasText: /^Passiv/ }).getAttribute('value'));
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await markiere(seite.getByText('Mitglied gespeichert.'));
    await foto(7);

    // Restore the original fee type.
    await oeffne(seite);
    await seite.locator('#mitglied-formular').getByLabel('Beitragsart', { exact: true }).selectOption(alt);
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await seite.getByText('Mitglied gespeichert.').waitFor();
}
