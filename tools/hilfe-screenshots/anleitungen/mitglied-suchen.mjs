/**
 * Recording of the guide "Mitglied suchen und öffnen" (app/data/hilfe/
 * 02-mitglied-suchen.php). Changes nothing.
 */

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await seite.getByRole('heading', { name: 'Mitglieder', level: 1 }).waitFor();
    await markiere(seite.getByRole('link', { name: 'Mitglieder', exact: true }).first());
    await foto(1);

    // A name finds the whole family Huber; the picture waits until the list
    // has changed (member 1023 is no Huber), as the tip of step 2 says.
    await seite.getByLabel('Mitglied suchen').fill('Huber');
    await seite.getByRole('row', { name: /\b1023\b/ }).waitFor({ state: 'detached' });
    await seite.getByRole('row', { name: /\b1001\b/ }).waitFor();
    await markiere(seite.getByLabel('Mitglied suchen'));
    await foto(2);

    // Extended search: opened, with a field filled in so the list is narrowed.
    await seite.getByText('Erweiterte Suche', { exact: true }).click();
    await seite.getByLabel('Ort', { exact: true }).waitFor();
    await markiere(seite.locator('#suche-erweitert > summary'));
    await foto(5);

    await seite.getByRole('row', { name: /\b1001\b/ }).getByRole('link').first().click();
    await seite.getByRole('link', { name: 'Daten', exact: true }).waitFor();
    await markiere(seite.getByRole('link', { name: 'Daten', exact: true }));
    await foto(6);
}
