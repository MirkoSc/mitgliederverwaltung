<?php

/**
 * Heading and tabs of the page "Statistik" (09 section 7); "Beiträge" only
 * for the admin (App\App\StatistikController::reiter()).
 *
 * @var array<string, array{string, string}> $reiter key => [label, address]
 * @var string $aktiverReiter
 */
?>
<h1>Statistik</h1>

<?php if (count($reiter) > 1): ?>
    <nav class="reiter" aria-label="Statistik">
        <ul>
            <?php foreach ($reiter as $schluessel => [$label, $adresse]): ?>
                <li><a href="<?= e($adresse) ?>"<?= $schluessel === $aktiverReiter ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
<?php endif; ?>
