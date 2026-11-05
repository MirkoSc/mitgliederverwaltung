/**
 * Recording of the guide "Mitglied suchen und öffnen" (app/data/hilfe/
 * 02-mitglied-suchen.php). Changes nothing.
 */

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await seite.getByRole('heading', { name: 'Mitglieder', level: 1 }).waitFor();
    await markiere(seite.getByRole('link', { name: 'Mitglieder', exact: true }).first());
    await foto(1);

    await seite.getByLabel('Mitglied suchen').fill('1001');
    await seite.getByRole('row', { name: /\b1001\b/ }).waitFor();
    await markiere(seite.getByLabel('Mitglied suchen'));
    await foto(2);

    await seite.getByRole('row', { name: /\b1001\b/ }).getByRole('link').first().click();
    await seite.getByRole('link', { name: 'Daten', exact: true }).waitFor();
    // Only one of the two ways back is visible, depending on the mode.
    await markiere(
        seite.getByRole('link', { name: 'Zurück zur Liste' })
            .or(seite.getByRole('link', { name: 'Schließen', exact: true })),
    );
    await foto(5);
}
