<?php

/**
 * The opened member beside the list (design system "Liste neben
 * Mitglied"); nothing without one - the list then has the whole width.
 * "Schließen" (PC) and "Zurück zur Liste" (phone, barrier-free mode) close
 * it. Hints on top, then the tabs - all of them
 * extensions found by file name (App\App\Mitglied\Reiter, ...Abschnitt),
 * which issue #19 and the issues after it fill. The "Daten" tab always
 * exists, so there is no tab-less fallback.
 *
 * @var \App\Domain\Mitglied|null $mitglied
 * @var \Closure $url
 * @var list<\App\App\Mitglied\Reiter> $reiter
 * @var string|null $gewaehlterReiter
 * @var string|null $reiterInhalt
 * @var list<string>|null $abschnitte
 * @var string|null $statusText
 * @var string|null $statusKlasse
 */

$bereichLink = require __DIR__ . '/bereich-link.php';
?>
<div class="geteilt-detail">
    <?php if ($mitglied !== null): ?>
        <a class="zurueck" <?= $bereichLink($url(['mitglied' => null, 'reiter' => null])) ?>>Zurück zur Liste</a>
        <div class="detail-kopf">
            <h2><?= e($mitglied->anzeigeName()) ?></h2>
            <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span>
            <span class="etikett <?= e($statusKlasse) ?>"><?= e($statusText) ?></span>
            <a class="knopf knopf-still detail-schliessen" <?= $bereichLink($url(['mitglied' => null, 'reiter' => null])) ?>>Schließen</a>
        </div>

        <?php foreach ($abschnitte ?? [] as $abschnitt): ?>
            <?= $abschnitt ?>
        <?php endforeach; ?>

        <nav class="reiter" aria-label="Bereiche des Mitglieds">
            <ul>
                <?php foreach ($reiter as $eintrag): ?>
                    <li><a <?= $bereichLink($url(['reiter' => $eintrag->schluessel()])) ?><?= $eintrag->schluessel() === $gewaehlterReiter ? ' aria-current="page"' : '' ?>><?= e($eintrag->titel()) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?= $reiterInhalt ?>
    <?php endif; ?>
</div>
