<?php

/**
 * The page "Beiträge" (docs/spec/08-beitraege.md sections 2 and 3, 06
 * section 2): the fee year from top to bottom - create the year's fees,
 * collect the open ones by direct debit (beitraege/einzug.php), then the
 * fees of the year, by default the open ones, each with "bezahlt" right
 * there and a checkbox for marking several as paid at once (#90; the
 * checkboxes belong to the form above the table through form="…", because
 * the rows hold forms of their own), and the archive of every direct
 * debit at the bottom (beitraege/einzug-archiv.php). Markup as in the pattern page
 * /admin/designsystem.
 *
 * @var int $jahr
 * @var int|null $vorjahr
 * @var int|null $folgejahr
 * @var string $filter
 * @var array<string, string> $filterWerte
 * @var array<string, array{anzahl: int, summe: int}> $summen by status value
 * @var int $gesamt
 * @var int $anzahl
 * @var list<\App\Domain\JahresbeitragZeile> $zeilen
 * @var int $seite
 * @var int $seiten
 * @var string $hier
 * @var \Closure(array<string, string|null>): string $link
 * @var list<\App\Domain\Zahlweise> $zahlweisen
 * @var \DateTimeImmutable $heute
 * @var \App\Service\Beitraege\EinzugAuswahl $einzugAuswahl
 * @var list<\App\Domain\Einzug> $einzuegeOffen
 * @var list<\App\Domain\Einzug> $einzuegeAlle
 * @var array<int, string|null> $einzugNamen
 * @var string|null $csrf
 */

use App\App\BeitraegeController;
use App\App\BeitragsjahrController;
use App\Domain\JahresbeitragStatus;
use App\View\Navigation;

$statusEtikett = require __DIR__ . '/status-etikett.php';
?>
<div class="seitenkopf">
    <h1>Beiträge</h1>
    <p>Das Beitragsjahr: Jahresbeiträge erzeugen, offene Beiträge per Lastschrift einziehen oder als bezahlt vermerken.</p>
</div>

<nav class="blaettern" aria-label="Beitragsjahr">
    <?php if ($vorjahr !== null): ?>
        <a class="knopf" href="<?= e(BeitraegeController::seitenAdresse($vorjahr)) ?>">Jahr <?= e($vorjahr) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <p class="blaettern-stand">Beitragsjahr <?= e($jahr) ?></p>
    <?php if ($folgejahr !== null): ?>
        <a class="knopf" href="<?= e(BeitraegeController::seitenAdresse($folgejahr)) ?>">Jahr <?= e($folgejahr) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</nav>

<section class="abschnitt beitragsjahr" aria-labelledby="erzeugen-titel">
    <h2 id="erzeugen-titel">Jahresbeiträge <?= e($jahr) ?></h2>
    <?php if ($gesamt === 0): ?>
        <p>Für <?= e($jahr) ?> gibt es noch keine Jahresbeiträge.</p>
    <?php else: ?>
        <ul class="beitragsjahr-stand">
            <?php foreach (JahresbeitragStatus::cases() as $status): ?>
                <li>
                    <?= $statusEtikett($status) ?>
                    <span><strong><?= e($summen[$status->value]['anzahl']) ?></strong>
                        <?= $summen[$status->value]['anzahl'] === 1 ? 'Beitrag' : 'Beiträge' ?>,
                        <?= e(geld($summen[$status->value]['summe'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p class="feld-hinweis" id="erzeugen-hilfe">Legt für jedes Mitglied mit Beitragsart den fehlenden Jahresbeitrag an –
        bei Eintritt in diesem Jahr anteilig. Ehemalige und beitragsfreie Mitglieder bekommen keinen. Sie können
        jederzeit erneut erzeugen: Es kommen nur fehlende dazu, etwa Neueintritte; vorhandene Beiträge bleiben, wie sie sind.</p>
    <div class="knopfreihe">
        <form method="post" action="<?= e(Navigation::BEITRAEGE) ?>/erzeugen">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <input type="hidden" name="jahr" value="<?= e($jahr) ?>">
            <button type="submit" class="knopf knopf-haupt" aria-describedby="erzeugen-hilfe">Beiträge für <?= e($jahr) ?> erzeugen</button>
        </form>
        <?php if ($gesamt > 0): ?>
            <a class="knopf knopf-still" href="<?= e(BeitragsjahrController::loeschAdresse($jahr)) ?>">Beiträge <?= e($jahr) ?> löschen</a>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/einzug.php'; ?>

<section class="beitraege-liste" aria-labelledby="liste-titel">
    <h2 id="liste-titel">Beiträge <?= e($jahr) ?></h2>

    <form class="suche" method="get" action="<?= e(Navigation::BEITRAEGE) ?>">
        <input type="hidden" name="jahr" value="<?= e($jahr) ?>">
        <div class="filter">
            <div class="feld">
                <label for="filter-status">Zeigen</label>
                <select id="filter-status" name="status">
                    <?php foreach ($filterWerte as $wert => $bezeichnung): ?>
                        <option value="<?= e($wert) ?>"<?= $wert === $filter ? ' selected' : '' ?>><?= e($bezeichnung) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="knopf">Anzeigen</button>
        </div>
    </form>

    <?php if ($zeilen === []): ?>
        <div class="leer">
            <?php if ($gesamt === 0): ?>
                <p>Noch keine Beiträge für <?= e($jahr) ?>. Erzeugen Sie sie oben.</p>
            <?php elseif ($filter === 'offen'): ?>
                <p>Alle Beiträge für <?= e($jahr) ?> sind bezahlt oder im Einzug.</p>
            <?php else: ?>
                <p>Keine Beiträge in dieser Auswahl.</p>
            <?php endif; ?>
            <?php if ($gesamt > 0 && $filter !== 'alle'): ?>
                <p class="knopfreihe"><a class="knopf" href="<?= e($link(['status' => 'alle'])) ?>">Alle Beiträge zeigen</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php $offenAufSeite = count(array_filter($zeilen, static fn($z): bool => $z->beitrag->status === JahresbeitragStatus::Offen)); ?>
        <?php if ($offenAufSeite > 0): ?>
            <form id="mehrfach-bezahlt" class="mehrfach-bezahlt" method="post" action="<?= e(BeitragsjahrController::PFAD_BEZAHLT) ?>" data-mehrfach-auswahl>
                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                <input type="hidden" name="zurueck" value="<?= e($hier) ?>">
                <fieldset class="mehrfach-felder">
                    <legend>Ausgewählte als bezahlt markieren</legend>
                    <p class="feld-hinweis" id="mehrfach-hilfe">Setzen Sie in der Liste ein Häkchen bei jedem offenen Beitrag, der bezahlt ist.</p>
                    <div class="mehrfach-kopf" data-mehrfach-kopf hidden>
                        <label class="feld-haken"><input type="checkbox" data-mehrfach-alle> Alle auswählen</label>
                        <p class="mehrfach-zaehler" role="status" data-mehrfach-zaehler>0 ausgewählt</p>
                        <p class="feld-hinweis">Mit gedrückter Umschalttaste (auch Umschalt + Leertaste) wählen Sie alle Beiträge
                            zwischen dem zuletzt angeklickten und diesem.</p>
                    </div>
                    <div class="mehrfach-angaben">
                        <div class="feld">
                            <label for="mehrfach-zahlweise">Wie bezahlt</label>
                            <select id="mehrfach-zahlweise" name="zahlweise">
                                <?php foreach ($zahlweisen as $zahlweise): ?>
                                    <option value="<?= e($zahlweise->value) ?>"><?= e($zahlweise->bezeichnung()) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="feld feld-kurz">
                            <label for="mehrfach-am">Bezahlt am</label>
                            <input type="date" id="mehrfach-am" name="bezahlt_am" value="<?= e($heute->format('Y-m-d')) ?>" required>
                        </div>
                        <div class="feld">
                            <label for="mehrfach-vermerk">Vermerk (optional)</label>
                            <input type="text" id="mehrfach-vermerk" name="vermerk" maxlength="500">
                        </div>
                        <button type="submit" class="knopf knopf-haupt" aria-describedby="mehrfach-hilfe">Ausgewählte als bezahlt markieren</button>
                    </div>
                </fieldset>
            </form>
        <?php endif; ?>
        <div class="tabelle-rahmen">
            <table class="liste">
                <caption><?= $anzahl === 1 ? '1 Beitrag' : e($anzahl) . ' Beiträge' ?>, nach Namen</caption>
                <thead>
                    <tr>
                        <?php if ($offenAufSeite > 0): ?>
                            <th scope="col" class="beitrag-auswahl"><span class="nur-lesehilfe">Auswahl</span></th>
                        <?php endif; ?>
                        <th scope="col">Mitglied</th>
                        <th scope="col">Nr.</th>
                        <th scope="col" class="zahl">Betrag</th>
                        <th scope="col">Status</th>
                        <th scope="col">Bezahlt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zeilen as $zeile): ?>
                        <?php $b = $zeile->beitrag; ?>
                        <tr>
                            <?php if ($offenAufSeite > 0): ?>
                                <td class="beitrag-auswahl">
                                    <?php if ($b->status === JahresbeitragStatus::Offen): ?>
                                        <label class="feld-haken"><input type="checkbox" name="bezahlt[]" value="<?= e($b->id) ?>" form="mehrfach-bezahlt" data-mehrfach-haken>
                                            <span class="nur-lesehilfe"><?= e($zeile->name()) ?> auswählen</span></label>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="liste-titel"><a href="<?= e(BeitraegeController::adresse((int) $b->id, $hier)) ?>"><?= e($zeile->name()) ?></a></td>
                            <td data-label="Nr."><span class="nummer"><?= e($zeile->mitgliedsnummer) ?></span></td>
                            <td data-label="Betrag" class="zahl"><?= e(geld($b->betragCent)) ?></td>
                            <td data-label="Status"><?= $statusEtikett($b->status) ?></td>
                            <td data-label="Bezahlt">
                                <?php if ($b->status === JahresbeitragStatus::Offen): ?>
                                    <form class="beitrag-schnell" method="post" action="<?= e(BeitraegeController::adresse((int) $b->id)) ?>/bezahlt">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                                        <input type="hidden" name="zurueck" value="<?= e($hier) ?>">
                                        <label class="nur-lesehilfe" for="zahlweise-<?= e($b->id) ?>">Zahlweise für <?= e($zeile->name()) ?></label>
                                        <select id="zahlweise-<?= e($b->id) ?>" name="zahlweise">
                                            <?php foreach ($zahlweisen as $zahlweise): ?>
                                                <option value="<?= e($zahlweise->value) ?>"><?= e($zahlweise->bezeichnung()) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="knopf">Heute bezahlt</button>
                                    </form>
                                <?php elseif ($b->status === JahresbeitragStatus::Bezahlt): ?>
                                    <?= e(datum($b->bezahltAm)) ?><?= $b->zahlweise !== null ? ', ' . e($b->zahlweise->bezeichnung()) : ($b->einzugId !== null ? ', Einzug' : '') ?>
                                <?php else: ?>
                                    Einzug Nr. <?= e($b->einzugId) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($offenAufSeite > 0): ?>
            <?php /* After the checkboxes: arrives only when nothing was cut on the way (max_input_vars). */ ?>
            <input type="hidden" name="<?= e(BeitragsjahrController::VOLLSTAENDIG) ?>" value="1" form="mehrfach-bezahlt">
        <?php endif; ?>

        <?php if ($seiten > 1): ?>
            <nav class="blaettern" aria-label="Seiten der Liste">
                <?php if ($seite > 1): ?>
                    <a class="knopf" href="<?= e($link(['seite' => $seite > 2 ? (string) ($seite - 1) : null])) ?>">Vorherige Seite</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <p class="blaettern-stand">Seite <?= e($seite) ?> von <?= e($seiten) ?>, je Seite <?= e(BeitraegeController::PRO_SEITE) ?> Beiträge</p>
                <?php if ($seite < $seiten): ?>
                    <a class="knopf" href="<?= e($link(['seite' => (string) ($seite + 1)])) ?>">Nächste Seite</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/einzug-archiv.php'; ?>
