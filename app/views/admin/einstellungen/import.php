<?php

/**
 * Settings section Import (docs/spec/10-import.md sections 1 and 4). Only
 * components of /admin/designsystem; the step chains run in
 * public/js/import.js and public/js/abgleich.js - external scripts, never
 * inline ones (CLAUDE.md section 4). The merge view of the reconciliation
 * is import-abgleich.php.
 *
 * @var string $zustand hochladen | vorschau | laeuft | bestand | abgleich | abgleich-laeuft
 * @var \App\Service\Import\ImportVorschau|null $vorschau
 * @var \App\Domain\Job|null $abgleichZuletzt the last finished reconciliation (stock not empty)
 * @var array<string, mixed>|null $abgleichStand position of the reconciliation chain
 * @var array<string, mixed> $stand position of the chain (job state)
 * @var \App\Domain\Job|null $zuletzt the last finished import
 * @var bool $kannLeeren
 * @var bool|null $dateiFehlt
 * @var string $version
 * @var string $csrf
 */

/** Rows shown in a long list; the rest is counted. */
$zeigen = 100;
$zahl = static fn(int $n): string => number_format($n, 0, ',', '.');
?>

<?php if ($zustand === 'hochladen'): ?>
    <p>
        Der Erstimport übernimmt die Mitglieder der bisherigen Software in einen leeren Bestand. Dafür
        wird die Datei <strong>import.csv</strong> gebraucht, die das Umwandlungsskript auf Ihrem Rechner
        aus den beiden Exportdateien erzeugt. Nach dem Hochladen sehen Sie zuerst eine Vorschau –
        importiert wird erst, wenn Sie es dort bestätigen.
    </p>
    <?php if ($dateiFehlt ?? false): ?>
        <p class="hinweis hinweis-warnung" role="status">Die zuletzt hochgeladene Datei gibt es nicht mehr. Bitte noch einmal hochladen.</p>
    <?php endif; ?>
    <?php $knopfText = 'Datei hochladen und prüfen'; require __DIR__ . '/import-hochladen.php'; ?>

<?php elseif ($zustand === 'vorschau' && $vorschau !== null): ?>
    <?php $fehlerhafteZeilen = count(array_unique(array_map(static fn($f): int => $f->zeile, $vorschau->fehler))); ?>
    <?php if ($vorschau->istFehlerfrei()): ?>
        <p class="hinweis hinweis-ok" role="status">Die Datei ist in Ordnung. Importiert ist noch nichts.</p>
    <?php else: ?>
        <p class="hinweis hinweis-fehler" role="alert">
            Die Datei enthält Fehler<?= $fehlerhafteZeilen > 0 ? ' in ' . e($zahl($fehlerhafteZeilen)) . ' Zeilen' : '' ?>.
            Importiert wird nur eine Datei ohne Fehler: bitte die Fehler beheben (im Umwandlungsskript oder in
            den Einstellungen) und die Datei neu hochladen.
        </p>
    <?php endif; ?>

    <dl class="angaben">
        <dt>Mitglieder</dt>
        <dd><?= e($zahl($vorschau->mitglieder)) ?><?= $vorschau->istFehlerfrei() ? '' : ' (ohne die fehlerhaften Zeilen)' ?></dd>
        <dt>Familien</dt>
        <dd><?= e($zahl($vorschau->familien)) ?></dd>
        <dt>Abteilungen</dt>
        <dd>
            <?php if ($vorschau->abteilungen === []): ?>
                keine Zuordnung
            <?php else: ?>
                <?php foreach ($vorschau->abteilungen as $name => $anzahl): ?>
                    <?= e($name) ?>: <?= e($zahl($anzahl)) ?><br>
                <?php endforeach; ?>
            <?php endif; ?>
        </dd>
    </dl>

    <?php if ($vorschau->unbekannteSpalten !== []): ?>
        <p class="hinweis hinweis-info">Diese Spalten kennt der Import nicht und ignoriert sie: <?= e(implode(', ', $vorschau->unbekannteSpalten)) ?>.</p>
    <?php endif; ?>

    <?php if ($vorschau->fehler !== []): ?>
        <h3>Fehler</h3>
        <div class="tabelle-rahmen">
            <table class="liste">
                <caption class="nur-lesehilfe">Fehler in der Datei</caption>
                <thead>
                    <tr>
                        <th scope="col" class="zahl">Zeile</th>
                        <th scope="col">Mitglied Nr.</th>
                        <th scope="col">Fehler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($vorschau->fehler, 0, $zeigen) as $fehler): ?>
                        <tr>
                            <td data-label="Zeile" class="zahl"><?= $fehler->zeile === 0 ? '–' : e($fehler->zeile) ?></td>
                            <td data-label="Mitglied Nr."><?= $fehler->nummer === null ? '–' : e($fehler->nummer) ?></td>
                            <td class="liste-titel"><?= e($fehler->text) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($vorschau->fehler) > $zeigen): ?>
            <p class="gedaempft">… und <?= e($zahl(count($vorschau->fehler) - $zeigen)) ?> weitere Fehler.</p>
        <?php endif; ?>
    <?php endif; ?>

    <h3>Hinweise</h3>
    <?php if ($vorschau->hinweise === []): ?>
        <p class="leer">Zu keinem Mitglied gibt es einen Hinweis.</p>
    <?php else: ?>
        <p>
            Hinweise sind keine Fehler: Diese Mitglieder werden importiert, sollten aber danach geprüft
            werden. Nachweise gehören nicht zum Import, deshalb fehlen hier die Hinweise zu Rente und Ausbildung.
        </p>
        <ul class="gedaempft">
            <?php foreach ($vorschau->hinweiseNachArt as $art => $anzahl): ?>
                <li><?= e($art) ?>: <?= e($zahl($anzahl)) ?></li>
            <?php endforeach; ?>
        </ul>
        <details>
            <summary>Die <?= e($zahl(count($vorschau->hinweise))) ?> Mitglieder mit Hinweisen anzeigen</summary>
            <div class="tabelle-rahmen">
                <table class="liste">
                    <caption class="nur-lesehilfe">Mitglieder mit Hinweisen</caption>
                    <thead>
                        <tr>
                            <th scope="col">Nr.</th>
                            <th scope="col">Name</th>
                            <th scope="col">Hinweise</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($vorschau->hinweise, 0, $zeigen) as $zeile): ?>
                            <tr>
                                <td data-label="Nr."><span class="nummer"><?= e($zeile['nummer']) ?></span></td>
                                <td data-label="Name"><?= e($zeile['name']) ?></td>
                                <td class="liste-titel">
                                    <?php foreach ($zeile['texte'] as $text): ?>
                                        <?= e($text) ?><br>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($vorschau->hinweise) > $zeigen): ?>
                <p class="gedaempft">… und <?= e($zahl(count($vorschau->hinweise) - $zeigen)) ?> weitere Mitglieder.</p>
            <?php endif; ?>
        </details>
    <?php endif; ?>

    <?php if ($vorschau->istFehlerfrei()): ?>
        <form method="post" action="/admin/import/starten" id="import-starten" data-csrf="<?= e($csrf) ?>" data-schritt="/admin/import/schritt">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <noscript>
                <p class="hinweis hinweis-fehler">Zum Importieren wird JavaScript benötigt.</p>
            </noscript>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-haupt" id="import-start">
                    <?= e($zahl($vorschau->mitglieder)) ?> Mitglieder importieren
                </button>
            </p>
        </form>
    <?php endif; ?>
    <form method="post" action="/admin/import/verwerfen">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-still">Datei verwerfen</button>
        </p>
    </form>
    <div id="import-verlauf" class="import-verlauf" hidden>
        <progress id="import-fortschritt" max="100" aria-labelledby="import-status"></progress>
        <p id="import-status" aria-live="polite"></p>
        <p id="import-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
    </div>

<?php elseif ($zustand === 'laeuft'): ?>
    <p class="hinweis hinweis-warnung" role="status">
        Ein Import ist angefangen: <?= e($zahl((int) ($stand['offset'] ?? 0))) ?> von
        <?= e($zahl((int) ($stand['gesamt'] ?? 0))) ?> Zeilen sind übernommen. Bitte den Import fortsetzen –
        bereits übernommene Mitglieder werden dabei nicht doppelt angelegt.
    </p>
    <noscript>
        <p class="hinweis hinweis-fehler">Zum Importieren wird JavaScript benötigt.</p>
    </noscript>
    <form method="post" action="/admin/import/verwerfen" id="import-weiter" data-csrf="<?= e($csrf) ?>" data-schritt="/admin/import/schritt">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <p class="knopfreihe">
            <button type="button" class="knopf knopf-haupt" id="import-fortsetzen">Import fortsetzen</button>
            <button type="submit" class="knopf knopf-still">Import abbrechen</button>
        </p>
    </form>
    <div id="import-verlauf" class="import-verlauf" hidden>
        <progress id="import-fortschritt" max="100" aria-labelledby="import-status"></progress>
        <p id="import-status" aria-live="polite"></p>
        <p id="import-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
    </div>

<?php elseif ($zustand === 'abgleich' || $zustand === 'abgleich-laeuft'): ?>
    <?php require __DIR__ . '/import-abgleich.php'; ?>

<?php else: ?>
    <?php if ($zuletzt !== null): ?>
        <p>
            Zuletzt importiert am <?= e(zeitpunkt($zuletzt->updatedAt)) ?> Uhr:
            <?= e($zahl((int) ($zuletzt->state['angelegt'] ?? 0))) ?> Mitglieder in
            <?= e($zahl((int) ($zuletzt->state['familien'] ?? 0))) ?> Familien.
        </p>
    <?php endif; ?>
    <?php if (($abgleichZuletzt ?? null) !== null): ?>
        <?php $s = $abgleichZuletzt->state; ?>
        <p>
            Letzter Abgleich am <?= e(zeitpunkt($abgleichZuletzt->updatedAt)) ?> Uhr:
            <?= e($zahl((int) ($s['neu'] ?? 0))) ?> neu, <?= e($zahl((int) ($s['ausgetreten'] ?? 0))) ?> ausgetreten,
            <?= e($zahl((int) ($s['geaendert'] ?? 0))) ?> geändert<?= (int) ($s['geloescht'] ?? 0) > 0 ? ', ' . e($zahl((int) $s['geloescht'])) . ' gelöscht' : '' ?>.
        </p>
    <?php endif; ?>
    <h3>Abgleich mit einer neuen Datei</h3>
    <p>
        Der Mitgliederbestand ist nicht leer, deshalb wird eine neue import.csv aus der alten Software
        abgeglichen statt importiert. Nach dem Hochladen sehen Sie, wer neu ist, wer in der Datei fehlt
        und bei wem sich etwas geändert hat. Übernommen wird nur, was Sie anhaken.
    </p>
    <?php if ($dateiFehlt ?? false): ?>
        <p class="hinweis hinweis-warnung" role="status">Die zuletzt hochgeladene Datei gibt es nicht mehr. Bitte noch einmal hochladen.</p>
    <?php endif; ?>
    <?php $knopfText = 'Datei hochladen und abgleichen'; require __DIR__ . '/import-hochladen.php'; ?>

    <h3>Bestand leeren</h3>
    <?php if ($kannLeeren): ?>
        <p>
            Für einen Probelauf lässt sich der Bestand leeren: Dann werden alle Mitglieder, Familien,
            Nachweise und Ehrungs-Vermerke gelöscht. Das geht nur, solange noch kein Jahresbeitrag
            erzeugt wurde.
        </p>
        <p><a class="knopf knopf-gefahr" href="/admin/import/leeren">Bestand leeren …</a></p>
    <?php else: ?>
        <p class="hinweis hinweis-info">Es gibt schon Jahresbeiträge. Deshalb lässt sich der Bestand nicht mehr leeren.</p>
    <?php endif; ?>
<?php endif; ?>

<?php if (in_array($zustand, ['vorschau', 'laeuft'], true)): ?>
    <script src="/js/import.js?v=<?= e($version) ?>" defer></script>
<?php elseif (in_array($zustand, ['abgleich', 'abgleich-laeuft'], true)): ?>
    <script src="/js/abgleich.js?v=<?= e($version) ?>" defer></script>
<?php endif; ?>
