<?php

/**
 * The question before the member stock is emptied (docs/spec/10-import.md
 * section 1; 06 section 4 "Rückfragen"): in whole sentences, what goes.
 *
 * @var int $anzahl
 * @var string $ziel
 * @var string $aktion
 * @var string|null $csrf
 */
?>
<div class="seitenkopf">
    <h1>Bestand leeren?</h1>
</div>

<div class="hinweis hinweis-warnung">
    <p>Alle <?= e($anzahl) ?> Mitglieder werden endgültig gelöscht – mit allen Versionen im Verlauf, allen
        Nachweisen samt Dateien, allen Ehrungs-Vermerken und allen Familien. Das lässt sich nicht
        rückgängig machen.</p>
    <p>Gedacht ist das für Probeläufe des Imports. Es geht nur, solange noch kein Jahresbeitrag erzeugt wurde.</p>
</div>

<form method="post" action="<?= e($aktion) ?>" class="knopfreihe">
    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
    <button type="submit" class="knopf knopf-gefahr">Bestand endgültig leeren</button>
    <a class="knopf knopf-still" href="<?= e($ziel) ?>">Nicht leeren, zurück zu den Einstellungen</a>
</form>
