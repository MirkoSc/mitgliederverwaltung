// Recording for app/data/hilfe/07-austritt-eintragen.php. Member 1028 (Rudolf
// Engel) leaves today; "Austritt zurücknehmen" restores him afterwards.

// Opens member 1028 on the tab "Daten" (the default) and waits for the button
// below the form.
async function oeffne(seite, knopf, ehemalige = false) {
    await seite.goto('/app/mitglieder');
    if (ehemalige) {
        await seite.getByLabel('Ehemalige anzeigen').check();
    }
    await seite.getByRole('row', { name: /\b1028\b/ }).getByRole('link').first().click();
    const link = seite.getByRole('link', { name: knopf, exact: true });
    await link.waitFor();

    return link;
}

export default async function ablauf({ seite, markiere, foto }) {
    const ausgetreten = await oeffne(seite, 'Mitglied ausgetreten');
    await markiere(ausgetreten);
    await foto(3);

    await ausgetreten.click();
    await seite.getByRole('heading', { name: 'Mitglied ausgetreten', level: 1 }).waitFor();
    await markiere(seite.getByLabel('Austrittsdatum'));
    await foto(4);

    await seite.getByLabel('Austrittsgrund').fill('Austritt');
    await seite.getByRole('button', { name: 'Austritt eintragen' }).click();
    await markiere(seite.getByText(/Austritt zum .* eingetragen – das Mitglied ist jetzt ehemalig\./));
    await foto(7);

    // Restore: still a member (steps 8 and 9 of the guide).
    await (await oeffne(seite, 'Austritt zurücknehmen', true)).click();
    await seite.getByRole('button', { name: 'Austritt zurücknehmen' }).click();
    await seite.getByText('Austritt zurückgenommen.').waitFor();
}
