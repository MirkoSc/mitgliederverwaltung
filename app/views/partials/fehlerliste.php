<?php

/**
 * What a form refused, as one message box (role=alert, so a screen reader
 * hears it right after the page loads).
 *
 * @var list<string> $fehler
 */
?>
<?php if (($fehler ?? []) !== []): ?>
    <div class="hinweis hinweis-fehler" role="alert">
        <?php foreach ($fehler as $satz): ?>
            <p><?= e($satz) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
