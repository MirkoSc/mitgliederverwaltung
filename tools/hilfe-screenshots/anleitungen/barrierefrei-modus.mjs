/**
 * Recording for app/data/hilfe/08-barrierefrei-modus.php. It switches the
 * mode of the demo account and switches it back, so the next recording finds
 * the account as before.
 */

async function modusAn(seite) {
    return seite.evaluate(() => document.documentElement.classList.contains('modus-barrierefrei'));
}

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    const vorher = await modusAn(seite);
    const umschalten = vorher ? 'Barrierefrei-Modus ausschalten' : 'Barrierefrei-Modus einschalten';
    const zurueck = vorher ? 'Barrierefrei-Modus einschalten' : 'Barrierefrei-Modus ausschalten';

    await seite.locator('details.benutzermenue > summary').click();
    await markiere(seite.getByRole('button', { name: umschalten, exact: true }));
    await foto(1);

    await seite.getByRole('button', { name: umschalten, exact: true }).click();
    await seite.getByRole('heading', { name: 'Mitglieder', level: 1 }).waitFor();
    if ((await modusAn(seite)) === vorher) {
        throw new Error('Der Modus hat sich nicht geändert.');
    }
    await foto(3);

    // Back to the starting state.
    await seite.locator('details.benutzermenue > summary').click();
    await seite.getByRole('button', { name: zurueck, exact: true }).click();
    await seite.getByRole('heading', { name: 'Mitglieder', level: 1 }).waitFor();
}
