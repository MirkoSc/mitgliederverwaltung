/**
 * Recording of the guide "Anschrift, Telefon oder E-Mail ändern"
 * (app/data/hilfe/04-anschrift-aendern.php). Changes the street of member
 * 1001 and puts the old value back at the end.
 */

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await seite.getByLabel('Mitglied suchen').fill('1001');
    await seite.getByRole('row', { name: /\b1001\b/ }).getByRole('link').first().click();
    await markiere(seite.getByRole('link', { name: 'Daten', exact: true }));
    await foto(2);

    const strasse = seite.getByLabel('Straße und Hausnummer');
    const alt = await strasse.inputValue();
    await strasse.fill('Beispielweg 7');
    await markiere(strasse);
    await foto(3);

    await markiere(seite.getByRole('button', { name: 'Mitglied speichern' }));
    await foto(5);
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await seite.getByText('Mitglied gespeichert.').waitFor();

    // Back to the sample data.
    await seite.getByLabel('Straße und Hausnummer').fill(alt);
    await seite.getByRole('button', { name: 'Mitglied speichern' }).click();
    await seite.getByText('Mitglied gespeichert.').waitFor();
}
