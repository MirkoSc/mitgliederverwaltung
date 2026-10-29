<?php

/**
 * The page "Mitglieder" (docs/spec/06-oberflaeche.md section 2): heading,
 * then the area of list and opened member (bereich.php), which htmx also
 * swaps on its own. Variables: see App\App\MitgliederController.
 *
 * Beside the heading: "Neues Mitglied" for whoever may edit members.
 *
 * @var bool $darfAnlegen
 */
?>
<div class="seitenkopf">
    <h1>Mitglieder</h1>
    <?php if ($darfAnlegen ?? false) : ?>
        <a class="knopf knopf-haupt" href="<?= e(\App\App\MitgliedController::PFAD_NEU) ?>">Neues Mitglied</a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/bereich.php'; ?>
