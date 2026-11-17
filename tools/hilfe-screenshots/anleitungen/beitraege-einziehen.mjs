// Recording for app/data/hilfe/13-beitraege-einziehen.php (Admin and
// Verwaltung: BeitraegeVerwalten). Creates the annual fees of the current
// year and direct debit Nr. 1 and leaves both: the recording
// einzug-bestaetigen.mjs runs next (alphabetical order), confirms the run and
// then restores the start state (no fees, no run). The sample data remove
// fees and runs before each mode anyway.

export const zugang = 'verwaltung';

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/beitraege');
    await seite.getByRole('heading', { name: 'Beiträge', level: 1 }).waitFor();

    const erzeugen = seite.getByRole('button', { name: /^Beiträge für \d{4} erzeugen$/ });
    await erzeugen.click();
    await seite.getByText(/^\d+ Jahresbeiträge für \d{4} erzeugt\.$/).waitFor();
    await markiere(erzeugen);
    await foto(2);

    // Up to 20 fees the list is open from the start (beitraege/einzug.php).
    const abschnitt = seite.locator('#einzug');
    const zeile = abschnitt.locator('summary', { hasText: /^\d+ Beiträge ansehen und abwählen$/ });
    const klappe = abschnitt.locator('details.einzug-klappe:not(.einzug-ausnahmen)');
    if (!(await klappe.evaluate((d) => d.open))) {
        await zeile.click();
    }
    await markiere(zeile);
    await foto(3);

    // One fee stays open this time (step 4): Werner Hahn, 1029.
    await abschnitt.getByRole('checkbox', { name: /^Hahn, Werner/ }).uncheck();

    // Step 5: the fees that cannot be collected, each with its reason.
    const ausnahmen = abschnitt.locator('details.einzug-ausnahmen');
    await ausnahmen.locator('summary', { hasText: /^\d+ offene Beiträge lassen sich nicht per Lastschrift einziehen$/ }).click();
    await ausnahmen.getByRole('columnheader', { name: 'Warum nicht' }).waitFor();

    const faellig = seite.getByLabel('Fällig am');
    await markiere(faellig);
    await foto(6);

    await seite.getByRole('button', { name: 'Einzug erzeugen' }).click();
    await seite.getByRole('heading', { name: 'Einzug Nr. 1', level: 1 }).waitFor();
    await seite.getByText(/^Einzug Nr\. 1 erzeugt: /).waitFor();
    await markiere(seite.getByRole('link', { name: 'Lastschriftdatei herunterladen' }));
    await foto(9);
}
