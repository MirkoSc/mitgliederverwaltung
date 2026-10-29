<?php

/**
 * Head of every installer step: the heading, where the run stands - an
 * ordered list, the steps really are a sequence - and what failed.
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $ueberschrift
 * @var array<string, string> $fehler
 */
$weg = $schritt->weg();
$erreicht = true;
?>
<header class="install-kopf">
    <p class="install-marke">Mitgliederverwaltung einrichten</p>
    <h1><?= e($ueberschrift) ?></h1>
    <ol class="install-schritte">
        <?php foreach ($weg as [$station, $titel]): ?>
            <?php $aktuell = $station === $schritt; ?>
            <li class="<?= $aktuell ? 'install-schritt-aktuell' : ($erreicht ? 'install-schritt-erledigt' : '') ?>"<?= $aktuell ? ' aria-current="step"' : '' ?>>
                <?= e($titel) ?><?php if ($erreicht && !$aktuell): ?><span class="nur-lesehilfe"> (erledigt)</span><?php endif; ?>
            </li>
            <?php $erreicht = $erreicht && !$aktuell; ?>
        <?php endforeach; ?>
    </ol>
</header>
<?php $fehler = array_values($fehler ?? []); require __DIR__ . '/fehlerliste.php'; ?>
