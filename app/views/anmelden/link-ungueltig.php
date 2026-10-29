<?php

/**
 * A reset or invitation link that is used, run out or never existed - one
 * page for all three, it makes no difference to the person holding it.
 *
 * @var string $title
 * @var string $meldung
 * @var string|null $ziel
 * @var string|null $zielText
 */
?>
<section class="meldeseite">
    <h1><?= e($title) ?></h1>
    <p><?= e($meldung) ?></p>
    <p class="knopfreihe">
        <?php if (($ziel ?? null) !== null): ?>
            <a class="knopf knopf-haupt" href="<?= e($ziel) ?>"><?= e((string) $zielText) ?></a>
        <?php endif; ?>
        <a href="/anmelden">Zur Anmeldung</a>
    </p>
</section>
