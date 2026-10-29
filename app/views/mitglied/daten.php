<?php

/**
 * The tab "Daten" of an opened member (App\App\Mitglied\DatenReiter): the
 * member form. Variables: see App\App\MitgliedController::formularDaten();
 * $formular is null when the member is gone.
 *
 * @var \App\App\Mitglied\MitgliedFormular|null $formular
 */
?>
<?php if ($formular === null): ?>
    <p class="hinweis hinweis-warnung">Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es inzwischen gelöscht.</p>
<?php else: ?>
    <?php require __DIR__ . '/formular.php'; ?>
<?php endif; ?>
