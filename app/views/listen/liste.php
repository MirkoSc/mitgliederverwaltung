<?php

/**
 * One list of the page "Listen" (docs/spec/09-listen-und-exporte.md,
 * 06-oberflaeche.md sections 2, 3 and 5): the lists as tabs on top, below
 * them the area of list and opened person (bereich.php), which htmx also
 * swaps on its own. Variables: see App\App\ListenController.
 *
 * @var string $title
 * @var list<\App\Listen\Liste> $listen
 * @var \App\Listen\ListenAnsicht $ansicht
 */

$liste = $ansicht->liste;
?>
<h1>Listen</h1>

<nav class="reiter" aria-label="Listen">
    <ul>
        <?php foreach ($listen as $eintrag): ?>
            <li><a href="/app/listen/<?= e($eintrag->schluessel()) ?>"<?= $eintrag->schluessel() === $liste->schluessel() ? ' aria-current="page"' : '' ?>><?= e($eintrag->titel()) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>

<?php require __DIR__ . '/bereich.php'; ?>
