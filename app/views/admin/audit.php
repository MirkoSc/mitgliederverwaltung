<?php

/**
 * The audit log: result of the chain check and the newest entries.
 *
 * @var \App\Service\Audit\KettenPruefung $pruefung
 * @var list<\App\Service\Audit\AuditEintrag> $eintraege
 * @var int $anzahl
 * @var array<int, string> $benutzerNamen user id => name
 */
$bezeichnungen = [];
foreach (\App\Domain\AuditAktion::cases() as $aktion) {
    $bezeichnungen[$aktion->value] = $aktion->bezeichnung();
}
?>
<h1>Audit-Log</h1>
<p><a href="/admin#audit">Zurück zu den Einstellungen</a></p>

<?php if ($pruefung->intakt()): ?>
    <p class="hinweis hinweis-ok" role="status">Kette in Ordnung: <?= e($pruefung->geprueft) ?> Einträge geprüft.</p>
<?php else: ?>
    <p class="hinweis hinweis-fehler" role="alert">Kette unterbrochen bei Eintrag <?= e($pruefung->bruchBeiId) ?>:
        <?= e($pruefung->grund) ?>. Das Audit-Log wurde verändert.</p>
<?php endif; ?>

<?php if ($eintraege === []): ?>
    <p>Noch keine Einträge.</p>
<?php else: ?>
    <p>Die neuesten <?= e(count($eintraege)) ?> von <?= e($anzahl) ?> Einträgen.</p>
    <div class="tabelle-rahmen">
        <table class="audit-tabelle">
            <caption class="nur-lesehilfe">Audit-Log, neueste zuerst</caption>
            <thead>
                <tr><th scope="col">Nr.</th><th scope="col">Zeit</th><th scope="col">Benutzer</th><th scope="col">Vorgang</th><th scope="col">Betrifft</th><th scope="col">Details</th></tr>
            </thead>
            <tbody>
                <?php foreach ($eintraege as $eintrag): ?>
                    <tr>
                        <td><?= e($eintrag->id) ?></td>
                        <td><?= e(zeitpunkt($eintrag->ts)) ?></td>
                        <td><?= $eintrag->userId === null ? '–' : e($benutzerNamen[$eintrag->userId] ?? 'Nr. ' . $eintrag->userId) ?></td>
                        <td><?= e($bezeichnungen[$eintrag->action] ?? $eintrag->action) ?></td>
                        <td><?= $eintrag->entity === null ? '–' : e($eintrag->entity . ($eintrag->entityId === null ? '' : ' ' . $eintrag->entityId)) ?></td>
                        <td><?= $eintrag->details === '{}' ? '–' : e($eintrag->details) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
