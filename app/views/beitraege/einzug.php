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
 * @var string $selbstKassierenAdresse
 * @var string|null $csrf
 */

use App\App\EinzugController;
use App\App\MitgliedController;
use App\Service\Beitraege\Einzuege;
use App\View\Navigation;

$auswahl = $einzugAuswahl;
$anzahl = count($auswahl->auswaehlbar);
$jahre = $auswahl->jahre();
?>
<section class="abschnitt einzug" id="einzug" aria-labelledby="einzug-titel">
    <h2 id="einzug-titel">Einzug per Lastschrift</h2>
    <p class="einzug-text">Offene Beiträge auswählen und den Einzug erzeugen, die Lastschriftdatei herunterladen und im
        Online-Banking einreichen. Hat die Bank eingezogen, bestätigen Sie den Einzug – dann sind die Beiträge bezahlt.</p>

    <?php if (!$auswahl->moeglich()): ?>
        <div class="hinweis hinweis-warnung">
            <p><strong>Noch keine Lastschriftdatei möglich.</strong> In den Vereinsdaten fehlt
                <?= e(implode(', ', $auswahl->fehlendeVereinsdaten)) ?>.</p>
            <p><a href="<?= e(Navigation::EINSTELLUNGEN) ?>#verein">Vereinsdaten in den Einstellungen ergänzen</a></p>
        </div>
    <?php endif; ?>

    <h3>Beiträge zusammenstellen</h3>
    <?php if ($anzahl === 0): ?>
        <div class="leer">
            <p>Keine offenen Beiträge, die per Lastschrift eingezogen werden können.</p>
        </div>
    <?php else: ?>
        <form class="formular einzug-formular" method="post" action="<?= e(Navigation::BEITRAEGE) ?>/einzug">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <ul class="beitragsjahr-stand">
                <li><span><strong><?= e($anzahl) ?></strong> <?= $anzahl === 1 ? 'offener Beitrag' : 'offene Beiträge' ?> mit Lastschrift</span></li>
                <li><span><strong><?= e(geld($auswahl->summeCent())) ?></strong> zusammen</span></li>
            </ul>
            <p class="einzug-text">Alle sind ausgewählt<?= count($jahre) > 1 ? ' (Beitragsjahre ' . e(implode(', ', $jahre)) . ')' : '' ?>.
                Wählen Sie ab, was diesmal nicht eingezogen werden soll.</p>

            <details class="einzug-klappe"<?= $anzahl <= 20 ? ' open' : '' ?>>
                <summary>Beiträge ansehen und abwählen</summary>
                <div class="tabelle-rahmen">
                    <table class="liste">
                        <caption><?= $anzahl === 1 ? '1 Beitrag' : e($anzahl) . ' Beiträge' ?>, nach Namen</caption>
                        <thead>
                            <tr>
                                <th scope="col">Mitglied</th>
                                <th scope="col">Nr.</th>
                                <th scope="col">Jahr</th>
                                <th scope="col" class="zahl">Betrag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auswahl->auswaehlbar as $k): ?>
                                <?php $id = (int) $k->beitrag->id; ?>
                                <tr>
                                    <td class="liste-titel">
                                        <label class="feld-haken"><input type="checkbox" name="beitrag[]" value="<?= e($id) ?>" checked>
                                            <span><?= e($k->name()) ?><span class="nur-lesehilfe">: Beitrag <?= e($k->beitrag->jahr) ?> einziehen</span></span></label>
                                    </td>
                                    <td data-label="Nr."><span class="nummer"><?= e($k->mitglied->mitgliedsnummer) ?></span></td>
                                    <td data-label="Jahr"><?= e($k->beitrag->jahr) ?></td>
                                    <td data-label="Betrag" class="zahl"><?= e(geld($k->beitrag->betragCent)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>

            <div class="feld feld-kurz">
                <label for="einzug-faellig">Fällig am</label>
                <input type="date" id="einzug-faellig" name="faellig_am" value="<?= e($auswahl->vorschlagFaelligkeit->format('Y-m-d')) ?>" required aria-describedby="einzug-faellig-hilfe">
                <p class="feld-hinweis" id="einzug-faellig-hilfe">An diesem Tag bucht die Bank die Beiträge ab. Vorgeschlagen ist der
                    nächste Bankarbeitstag plus einen Tag, damit die Datei rechtzeitig bei der Bank ist.</p>
            </div>
            <p class="feld-hinweis einzug-text">Verwendungszweck: „<?= e($auswahl->verwendungszweck) ?>“ –
                <?= e(implode(', ', array_map(static fn(string $p, string $b): string => $p . ' = ' . $b, array_keys(Einzuege::PLATZHALTER), Einzuege::PLATZHALTER))) ?>.
                Die Vorlage steht in den Vereinsdaten.</p>

            <input type="hidden" name="<?= e(EinzugController::VOLLSTAENDIG) ?>" value="1">
            <?php if ($auswahl->moeglich()): ?>
                <button type="submit" class="knopf knopf-haupt">Einzug erzeugen</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($auswahl->nichtAuswaehlbar !== []): ?>
        <?php $ohne = count($auswahl->nichtAuswaehlbar); ?>
        <details class="einzug-klappe einzug-ausnahmen">
            <summary><?= $ohne === 1 ? '1 offener Beitrag lässt' : e($ohne) . ' offene Beiträge lassen' ?> sich nicht per Lastschrift einziehen</summary>
            <p class="einzug-text">Selbstzahler und Beiträge ohne gültige IBAN stehen in der Liste
                <a href="<?= e($selbstKassierenAdresse) ?>">„Selbst kassieren“</a>. Fehlt eine Angabe zum Mandat, ergänzen Sie sie am Mitglied.</p>
            <div class="tabelle-rahmen">
                <table class="liste">
                    <caption><?= $ohne === 1 ? '1 Beitrag' : e($ohne) . ' Beiträge' ?>, nach Namen</caption>
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
                        <?php foreach ($auswahl->nichtAuswaehlbar as $k): ?>
                            <tr>
                                <td class="liste-titel"><a href="<?= e(MitgliedController::adresse((int) $k->mitglied->id)) ?>"><?= e($k->name()) ?></a></td>
                                <td data-label="Nr."><span class="nummer"><?= e($k->mitglied->mitgliedsnummer) ?></span></td>
                                <td data-label="Jahr"><?= e($k->beitrag->jahr) ?></td>
                                <td data-label="Betrag" class="zahl"><?= e(geld($k->beitrag->betragCent)) ?></td>
                                <td data-label="Warum nicht"><?= e((string) $k->grund) ?></td>
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
