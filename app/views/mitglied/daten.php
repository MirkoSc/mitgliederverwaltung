<?php

/**
 * The tab "Daten" of an opened member (App\App\Mitglied\DatenReiter): the
 * member form, or - for an account without mitglieder.bearbeiten (role
 * "Nur lesen", issue #87) - the same data to read only. Variables: see
 * App\App\MitgliedController::formularDaten(); $formular is null when the
 * member is gone.
 *
 * @var \App\App\Mitglied\MitgliedFormular|null $formular
 * @var bool $darfBearbeiten
 */
?>
<?php if ($formular === null): ?>
    <p class="hinweis hinweis-warnung">Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es inzwischen gelöscht.</p>
<?php elseif (!$darfBearbeiten): ?>
    <?php require __DIR__ . '/daten-lesen.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/formular.php'; ?>
<?php endif; ?>
