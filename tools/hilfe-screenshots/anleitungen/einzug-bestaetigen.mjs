// Recording for app/data/hilfe/14-einzug-bestaetigen.php (Admin and
// Verwaltung: BeitraegeVerwalten). Uses the direct debit that
// beitraege-einziehen.mjs left waiting for the bank - or, run alone
// (--nur), creates the fees and a run first. Confirms it, takes one fee
// back as returned debit (Peter Vogel, 1016) and at the end deletes the
// fees of the year with their runs ("Beiträge <Jahr> löschen"): the start
// state again, no fees and no run.

export const zugang = 'verwaltung';

async function einzugBereitstellen(seite) {
    await seite.goto('/app/beitraege');
    if (await seite.getByRole('heading', { name: 'Erzeugt, wartet auf die Bank', level: 3 }).count() > 0) {
        return;
    }
    if (await seite.getByRole('button', { name: 'Einzug erzeugen' }).count() === 0) {
        await seite.getByRole('button', { name: /^Beiträge für \d{4} erzeugen$/ }).click();
        await seite.getByText(/Jahresbeiträge? für \d{4} erzeugt\./).waitFor();
    }
    await seite.getByRole('button', { name: 'Einzug erzeugen' }).click();
    await seite.getByText(/^Einzug Nr\. \d+ erzeugt: /).waitFor();
    await seite.goto('/app/beitraege');
}

export default async function ablauf({ seite, markiere, foto }) {
    await einzugBereitstellen(seite);

    const karte = seite.locator('article.einzug-karte').first();
    await markiere(karte.getByRole('heading', { name: /^Einzug Nr\. \d+, fällig am / }));
    await foto(2);

    await karte.getByLabel('Ja, die Bank hat diesen Einzug ausgeführt.').check();
    await karte.getByRole('button', { name: 'Einzug bestätigen' }).click();
    const bestaetigt = seite.getByText(/^Einzug Nr\. \d+ bestätigt: Die Beiträge sind zum .* bezahlt\.$/);
    await markiere(bestaetigt);
    await foto(4);

    await seite.locator('#einzug-archiv').getByRole('link', { name: /^Nr\. \d+$/ }).first().click();
    await seite.getByRole('heading', { name: /^Einzug Nr\. \d+$/, level: 1 }).waitFor();
    await seite.getByRole('checkbox', { name: /^Vogel, Peter: Rücklastschrift/ }).check();
    await markiere(seite.getByRole('row', { name: /\b1016\b/ }));
    await foto(6);

    await seite.getByLabel('Vermerk', { exact: true }).fill('Konto erloschen');
    await seite.getByRole('button', { name: 'Ausgewählte als Rücklastschrift zurücksetzen' }).click();
    await markiere(seite.getByText(/^1 Beitrag als Rücklastschrift vermerkt \(.*\) – wieder offen\.$/));
    await foto(8);

    // Restore: delete the fees of the year together with their runs.
    await seite.goto('/app/beitraege');
    await seite.getByRole('link', { name: /^Beiträge \d{4} löschen$/ }).click();
    await seite.getByLabel(/^Zum Bestätigen .* eintippen$/).fill('LÖSCHEN');
    await seite.getByRole('button', { name: /^Beiträge \d{4} endgültig löschen$/ }).click();
    await seite.getByText(/^Die Beiträge \d{4} sind gelöscht/).waitFor();
}
