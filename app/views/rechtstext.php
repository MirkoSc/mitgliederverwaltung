<?php

/**
 * A public legal page (Impressum, Datenschutz). $html is already escaped
 * and marked up by App\Support\Markdown.
 *
 * @var string $title
 * @var string $html
 * @var bool $leer
 */
?>
<h1><?= e($title) ?></h1>
<?php if ($leer): ?>
    <p class="hinweis hinweis-info">Dieser Text ist noch nicht eingetragen. Der Verein trägt ihn unter
        Einstellungen › Rechtstexte ein.</p>
<?php else: ?>
    <article class="rechtstext"><?= $html ?></article>
<?php endif; ?>
