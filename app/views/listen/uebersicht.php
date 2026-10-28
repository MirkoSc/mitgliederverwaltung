<?php

/**
 * The selection of the page "Listen" (docs/spec/06-oberflaeche.md
 * section 2): every list the account may see.
 *
 * @var list<\App\Listen\Liste> $listen
 */
?>
<h1>Listen</h1>
<?php if ($listen === []): ?>
    <div class="platzhalter">
        <p>Hier erscheinen die Listen: Geburtstage, Ehrungen, Funktionen, Auffälligkeiten, die BLSV-Meldung
            und der Gesamtexport. Noch ist keine eingerichtet.</p>
    </div>
<?php else: ?>
    <ul class="auswahl">
        <?php foreach ($listen as $liste): ?>
            <li>
                <a href="/app/listen/<?= e($liste->schluessel()) ?>"><?= e($liste->titel()) ?></a>
                <p><?= e($liste->beschreibung()) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
