<?php

/**
 * The member form on a page of its own: "Neues Mitglied", and a member
 * whose save could not be done (a required field, the number taken,
 * somebody else saved in between). Variables: see
 * App\App\MitgliedController::formularDaten().
 *
 * @var string $title
 * @var string|null $meldung
 * @var \App\Domain\Mitglied|null $mitglied
 */
?>
<div class="seitenkopf">
    <h1><?= e($title) ?></h1>
    <?php if ($mitglied !== null): ?>
        <p><?= e($mitglied->anzeigeName()) ?>, <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span></p>
    <?php else: ?>
        <p>Pflicht sind nur Mitgliedsnummer und Nachname. Was fehlt, können Sie später ergänzen.</p>
    <?php endif; ?>
</div>

<?php if (($meldung ?? null) !== null): ?>
    <p class="hinweis hinweis-warnung" role="alert"><?= e($meldung) ?></p>
<?php endif; ?>

<?php require __DIR__ . '/formular.php'; ?>
