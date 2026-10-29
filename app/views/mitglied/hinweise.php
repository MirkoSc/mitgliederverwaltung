<?php

/**
 * The hints at the top of an opened member (App\App\Mitglied\
 * HinweiseAbschnitt). Nothing at all when there are none. They warn and
 * never block.
 *
 * @var list<\App\Service\Mitglied\Hinweis> $hinweise
 */
?>
<?php if ($hinweise !== []): ?>
    <div class="hinweis hinweis-warnung" id="mitglied-hinweise">
        <strong><?= count($hinweise) === 1 ? 'Ein Hinweis zu diesem Mitglied' : e(count($hinweise)) . ' Hinweise zu diesem Mitglied' ?></strong>
        <ul>
            <?php foreach ($hinweise as $hinweis): ?>
                <li><?= e($hinweis->text) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
