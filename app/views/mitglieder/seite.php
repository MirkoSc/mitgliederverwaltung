<?php

/**
 * The page "Mitglieder" (docs/spec/06-oberflaeche.md section 2): heading,
 * then the area of list and opened member (bereich.php), which htmx also
 * swaps on its own. Variables: see App\App\MitgliederController.
 *
 * Issue #19 adds "Neues Mitglied" beside the heading.
 */
?>
<div class="seitenkopf">
    <h1>Mitglieder</h1>
</div>

<?php require __DIR__ . '/bereich.php'; ?>
