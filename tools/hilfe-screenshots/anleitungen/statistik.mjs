/** Recording for app/data/hilfe/11-statistik.php. */

export default async function ablauf({ seite, markiere, foto }) {
    const leiste = seite.getByRole('navigation', { name: 'Hauptnavigation' });

    await seite.goto('/app/mitglieder');
    await markiere(leiste.getByRole('link', { name: 'Statistik', exact: true }));
    await foto(1);

    await leiste.getByRole('link', { name: 'Statistik', exact: true }).click();
    await seite.getByRole('heading', { name: 'Statistik', level: 1 }).waitFor();
    await seite.getByRole('link', { name: 'Mitglieder', exact: true }).last().waitFor();
    await seite.getByText('Durchschnittsalter in Jahren').waitFor();
    await markiere(seite.locator('dl.kennzahlen'));
    await foto(2);

    await seite.getByRole('navigation', { name: 'Auswertungen' }).getByRole('link', { name: 'Altersgruppen', exact: true }).waitFor();
    await seite.getByRole('heading', { name: 'Altersgruppen', level: 2 }).waitFor();

    // Charts are drawn by Chart.js without animation; wait until the canvas
    // exists and the table of the first section is folded away (normal mode),
    // or is open without a chart (Barrierefrei-Modus).
    const abschnitt = seite.locator('#altersgruppen');
    await abschnitt.scrollIntoViewIfNeeded();
    const barrierefrei = await seite.evaluate(() => document.documentElement.classList.contains('modus-barrierefrei'));
    if (!barrierefrei) {
        await abschnitt.locator('canvas').waitFor();
        await seite.waitForFunction(() => {
            const c = document.querySelector('#altersgruppen canvas');
            return c !== null && c.width > 0 && c.height > 0;
        });
        await seite.waitForTimeout(500);
    }
    await abschnitt.getByText('Zahlen als Tabelle', { exact: true }).waitFor();
    await markiere(abschnitt.getByText('Zahlen als Tabelle', { exact: true }));
    await foto(5);
}
