/** Recording for app/data/hilfe/09-geburtstagsliste.php. */

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await markiere(seite.getByRole('navigation', { name: 'Hauptnavigation' }).getByRole('link', { name: 'Listen', exact: true }));
    await foto(1);

    await seite.getByRole('navigation', { name: 'Hauptnavigation' }).getByRole('link', { name: 'Listen', exact: true }).click();
    await seite.getByRole('navigation', { name: 'Listen' }).getByRole('link', { name: 'Geburtstage', exact: true }).click();
    await seite.getByRole('heading', { name: 'Geburtstage', level: 2 }).waitFor();

    await seite.getByLabel('Nur runde Geburtstage').waitFor();
    await seite.getByRole('button', { name: 'Anzeigen', exact: true }).waitFor();
    await seite.getByRole('link', { name: 'Vor', exact: true }).waitFor();
    await seite.getByRole('link', { name: 'Zurück', exact: true }).waitFor();
    await markiere(seite.getByRole('combobox', { name: 'Zeitraum' }));
    await foto(3);

    await seite.getByRole('heading', { name: 'Liste exportieren' }).waitFor();
    await seite.getByRole('button', { name: 'Liste exportieren', exact: true }).waitFor();
    await markiere(seite.getByRole('combobox', { name: 'Dateiformat' }));
    await foto(6);
}
