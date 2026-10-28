<?php

/**
 * Error page: what happened and what to do - never details
 * (docs/spec/01-plattform.md section 7). A 500 shows the reference that
 * ties it to the log line.
 *
 * @var string $title
 * @var string $meldung
 * @var string|null $referenz
 * @var string|null $startseite
 */
?>
<section class="meldeseite">
    <h1><?= e($title) ?></h1>
    <p><?= e($meldung) ?></p>
    <?php if (($referenz ?? null) !== null): ?>
        <p class="referenz">Referenz: <strong><?= e($referenz) ?></strong></p>
    <?php endif; ?>
    <p><a href="<?= e($startseite ?? '/') ?>">Zur Startseite</a></p>
</section>
