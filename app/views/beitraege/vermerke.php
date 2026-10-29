<?php

/**
 * The note list of an annual fee (docs/spec/08-beitraege.md section 2):
 * who, when, what - oldest first, as written. Included by the page of one
 * fee and the member tab.
 *
 * @var list<\App\Domain\Vermerk> $vermerke
 * @var array<int, string|null> $namen user id => name, null when the account is gone
 */
?>
<?php if ($vermerke === []): ?>
    <p class="gedaempft">Keine Vermerke.</p>
<?php else: ?>
    <ol class="vermerke">
        <?php foreach ($vermerke as $vermerk): ?>
            <li>
                <?= e($vermerk->text) ?>
                <span class="vermerke-wer"><?= e(zeitpunkt($vermerk->am)) ?>, <?= e($vermerk->von === null ? 'automatisch' : ($namen[$vermerk->von] ?? null) ?? 'gelöschter Zugang') ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
