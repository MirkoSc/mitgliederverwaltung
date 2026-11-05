/**
 * Probe recording (issue #115): shows how a recording looks and checks the
 * script against the page "Mitglieder". It belongs to no guide, so it is not
 * reconciled with app/data/hilfe/ and its pictures go to the git-ignored
 * tools/hilfe-screenshots/probe-ausgabe/ - never to public/img/hilfe/.
 *
 * A real recording is called after its guide: anleitungen/<slug>.mjs.
 */

export const probe = true;

export default async function ablauf({ seite, markiere, foto }) {
    await seite.goto('/app/mitglieder');
    await seite.getByRole('heading', { name: 'Mitglieder', level: 1 }).waitFor();

    // Elements by role and German label, members by their number: a changed
    // page then fails the recording at once.
    await seite.getByRole('row', { name: /\b1001\b/ }).waitFor();
    await markiere(seite.getByRole('link', { name: 'Neues Mitglied' }));
    await foto(1);
}
