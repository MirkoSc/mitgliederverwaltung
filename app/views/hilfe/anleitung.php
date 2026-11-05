<?php

/**
 * One guide of the help (docs/spec/12-hilfe.md section 3): the steps as a
 * numbered list, each with its text, an optional tip and an optional
 * picture in the variant of the account's mode. Texts are plain text with
 * **bold** only - escaped by App\Support\Markdown::fett(). No JavaScript.
 *
 * @var \App\Service\Hilfe\Anleitung $anleitung
 * @var array<int, \App\Service\Hilfe\Bild|null> $bilder by step number
 * @var \App\Service\Hilfe\Anleitung|null $naechste
 */

use App\App\HilfeController;
use App\Support\Markdown;
use App\View\Navigation;

?>
<p class="anleitung-zurueck"><a href="<?= e(Navigation::HILFE) ?>">Alle Anleitungen</a></p>

<div class="seitenkopf">
    <h1><?= e($anleitung->titel) ?></h1>
    <p><?= e($anleitung->kurz) ?></p>
</div>

<ol class="anleitung">
    <?php foreach ($anleitung->schritte as $schritt): ?>
        <?php $bild = $bilder[$schritt->nummer] ?? null; ?>
        <li class="anleitung-schritt">
            <p class="anleitung-text"><?= Markdown::fett($schritt->text) ?></p>
            <?php if ($schritt->tipp !== null): ?>
                <div class="hinweis hinweis-info anleitung-tipp">
                    <p>Tipp: <?= Markdown::fett($schritt->tipp) ?></p>
                </div>
            <?php endif; ?>
            <?php if ($bild !== null): ?>
                <img class="anleitung-bild" src="<?= e($bild->url) ?>" alt="<?= e($bild->alt) ?>"
                     width="<?= $bild->breite ?>" height="<?= $bild->hoehe ?>" loading="lazy">
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>

<nav class="anleitung-weiter" aria-label="Weitere Anleitungen">
    <?php if ($naechste !== null): ?>
        <p>Nächste Anleitung: <a href="<?= e(HilfeController::adresse($naechste->slug)) ?>"><?= e($naechste->titel) ?></a></p>
    <?php endif; ?>
    <p><a href="<?= e(Navigation::HILFE) ?>">Alle Anleitungen</a></p>
</nav>
