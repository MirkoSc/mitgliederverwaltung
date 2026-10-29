<?php

/**
 * Section "Einzug" of the page "Beiträge" (docs/spec/08-beitraege.md
 * section 3), in the order of the steps: compose and create, then every run
 * that waits for the bank with its file, "bestätigen" and "verwerfen".
 * Included by beitraege/seite.php, which holds the variables.
 *
 * The checkboxes are preselected and are sent as `beitrag[]`; the hidden
 * field after them tells the server the list arrived complete (a server
 * cuts input beyond max_input_vars without a word).
 *
 * @var \App\Service\Beitraege\EinzugAuswahl $einzugAuswahl
 * @var list<\App\Domain\Einzug> $einzuegeOffen
 * @var array<int, string|null> $einzugNamen
 * @var string|null $csrf
 */

use App\App\EinzugController;
use App\App\MitgliedController;
use App\Service\Beitraege\Einzuege;
use App\View\Navigation;

// Own names only: this file runs inside beitraege/seite.php, whose variables
// ($einzugAnzahl, $zeilen …) are still needed after it.
$einzugAnzahl = count($einzugAuswahl->auswaehlbar);
$einzugJahre = $einzugAuswahl->jahre();
?>
<section class="abschnitt einzug" id="einzug" aria-labelledby="einzug-titel">
    <h2 id="einzug-titel">Einzug per Lastschrift</h2>
    <p class="einzug-text">Offene Beiträge auswählen und den Einzug erzeugen, die Lastschriftdatei herunterladen und im
        Online-Banking einreichen. Hat die Bank eingezogen, bestätigen Sie den Einzug – dann sind die Beiträge bezahlt.</p>

    <?php if (!$einzugAuswahl->moeglich()): ?>
        <div class="hinweis hinweis-warnung">
            <p><strong>Noch keine Lastschriftdatei möglich.</strong> In den Vereinsdaten fehlt
                <?= e(implode(', ', $einzugAuswahl->fehlendeVereinsdaten)) ?>.</p>
            <p><a href="<?= e(Navigation::EINSTELLUNGEN) ?>#verein">Vereinsdaten in den Einstellungen ergänzen</a></p>
        </div>
    <?php endif; ?>

    <h3>Beiträge zusammenstellen</h3>
    <?php if ($einzugAnzahl === 0): ?>
        <div class="leer">
            <p>Keine offenen Beiträge, die per Lastschrift eingezogen werden können.</p>
        </div>
    <?php else: ?>
        <form class="formular einzug-formular" method="post" action="<?= e(Navigation::BEITRAEGE) ?>/einzug">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <ul class="beitragsjahr-stand">
                <li><span><strong><?= e($einzugAnzahl) ?></strong> <?= $einzugAnzahl === 1 ? 'offener Beitrag' : 'offene Beiträge' ?> mit Lastschrift</span></li>
                <li><span><strong><?= e(geld($einzugAuswahl->summeCent())) ?></strong> zusammen</span></li>
            </ul>
            <p class="einzug-text">Alle sind ausgewählt<?= count($einzugJahre) > 1 ? ' (Beitragsjahre ' . e(implode(', ', $einzugJahre)) . ')' : '' ?>.
                Wählen Sie ab, was diesmal nicht eingezogen werden soll.</p>

            <details class="einzug-klappe"<?= $einzugAnzahl <= 20 ? ' open' : '' ?>>
                <summary>Beiträge ansehen und abwählen</summary>
                <div class="tabelle-rahmen">
                    <table class="liste">
                        <caption><?= $einzugAnzahl === 1 ? '1 Beitrag' : e($einzugAnzahl) . ' Beiträge' ?>, nach Namen</caption>
                        <thead>
                            <tr>
                                <th scope="col">Mitglied</th>
                                <th scope="col">Nr.</th>
                                <th scope="col">Jahr</th>
                                <th scope="col" class="zahl">Betrag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($einzugAuswahl->auswaehlbar as $kandidat): ?>
                                <?php $einzugBeitragId = (int) $kandidat->beitrag->id; ?>
                                <tr>
                                    <td class="liste-titel">
                                        <label class="feld-haken"><input type="checkbox" name="beitrag[]" value="<?= e($einzugBeitragId) ?>" checked>
                                            <span><?= e($kandidat->name()) ?><span class="nur-lesehilfe">: Beitrag <?= e($kandidat->beitrag->jahr) ?> einziehen</span></span></label>
                                    </td>
                                    <td data-label="Nr."><span class="nummer"><?= e($kandidat->mitglied->mitgliedsnummer) ?></span></td>
                                    <td data-label="Jahr"><?= e($kandidat->beitrag->jahr) ?></td>
                                    <td data-label="Betrag" class="zahl"><?= e(geld($kandidat->beitrag->betragCent)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>

            <div class="feld feld-kurz">
                <label for="einzug-faellig">Fällig am</label>
                <input type="date" id="einzug-faellig" name="faellig_am" value="<?= e($einzugAuswahl->vorschlagFaelligkeit->format('Y-m-d')) ?>" required aria-describedby="einzug-faellig-hilfe">
                <p class="feld-hinweis" id="einzug-faellig-hilfe">An diesem Tag bucht die Bank die Beiträge ab. Vorgeschlagen ist der
                    nächste Bankarbeitstag plus einen Tag, damit die Datei rechtzeitig bei der Bank ist.</p>
            </div>
            <p class="feld-hinweis einzug-text">Verwendungszweck: „<?= e($einzugAuswahl->verwendungszweck) ?>“ –
                <?= e(implode(', ', array_map(static fn(string $p, string $b): string => $p . ' = ' . $b, array_keys(Einzuege::PLATZHALTER), Einzuege::PLATZHALTER))) ?>.
                Die Vorlage steht in den Vereinsdaten.</p>

            <input type="hidden" name="<?= e(EinzugController::VOLLSTAENDIG) ?>" value="1">
            <?php if ($einzugAuswahl->moeglich()): ?>
                <button type="submit" class="knopf knopf-haupt">Einzug erzeugen</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($einzugAuswahl->nichtAuswaehlbar !== []): ?>
        <?php
        $einzugOhne = count($einzugAuswahl->nichtAuswaehlbar);
        $einzugSelbst = count(array_filter($einzugAuswahl->nichtAuswaehlbar, static fn($k): bool => $k->selbstKassieren));
        ?>
        <details class="einzug-klappe einzug-ausnahmen">
            <summary><?= $einzugOhne === 1 ? '1 offener Beitrag lässt' : e($einzugOhne) . ' offene Beiträge lassen' ?> sich nicht per Lastschrift einziehen</summary>
            <p class="einzug-text">
                <?php if ($einzugSelbst > 0): ?>
                    Selbstzahler und Beiträge ohne gültige IBAN kassieren Sie selbst – sie stehen in der Liste
                    <a href="<?= e(Navigation::LISTEN) ?>/selbst-kassieren">„Selbst kassieren“</a>.
                <?php endif; ?>
                <?php if ($einzugSelbst < $einzugOhne): ?>
                    Fehlt eine Angabe zum Mandat, ergänzen Sie sie am Mitglied; danach lässt sich der Beitrag einziehen.
                <?php endif; ?>
            </p>
            <div class="tabelle-rahmen">
                <table class="liste">
                    <caption><?= $einzugOhne === 1 ? '1 Beitrag' : e($einzugOhne) . ' Beiträge' ?>, nach Namen</caption>
                    <thead>
                        <tr>
                            <th scope="col">Mitglied</th>
                            <th scope="col">Nr.</th>
                            <th scope="col">Jahr</th>
                            <th scope="col" class="zahl">Betrag</th>
                            <th scope="col">Warum nicht</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($einzugAuswahl->nichtAuswaehlbar as $kandidat): ?>
                            <tr>
                                <td class="liste-titel"><a href="<?= e(MitgliedController::adresse((int) $kandidat->mitglied->id)) ?>"><?= e($kandidat->name()) ?></a></td>
                                <td data-label="Nr."><span class="nummer"><?= e($kandidat->mitglied->mitgliedsnummer) ?></span></td>
                                <td data-label="Jahr"><?= e($kandidat->beitrag->jahr) ?></td>
                                <td data-label="Betrag" class="zahl"><?= e(geld($kandidat->beitrag->betragCent)) ?></td>
                                <td data-label="Warum nicht"><?= e((string) $kandidat->grund) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($einzuegeOffen !== []): ?>
        <h3>Erzeugt, wartet auf die Bank</h3>
        <?php foreach ($einzuegeOffen as $einzug): ?>
            <?php require __DIR__ . '/einzug-karte.php'; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
