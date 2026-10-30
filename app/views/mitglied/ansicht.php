<?php

/**
 * The opened member as a building block (App\App\Mitglied\MitgliedAnsicht,
 * docs/spec/06-oberflaeche.md section 5): the right half of "Liste neben
 * Mitglied"; an empty div without a member - the list then has the whole
 * width. "Schließen" (PC) and "Zurück zur Liste" (phone) close it - or one
 * link of the embedding's own text on both (Einbettung::$zurueckText, the
 * families: "Zurück zur Familie"). Hints on
 * top, then the tabs - extensions found by file name (App\App\Mitglied\
 * Reiter, ...Abschnitt). Every link leads back to the embedding page
 * ($einbettung), never to another one.
 *
 * The heading takes the focus when the member was just opened, the chosen
 * tab when a tab was switched (htmx focuses [autofocus] after a swap) - in
 * the Barrierefrei-Modus the member stands below the list, so a keyboard
 * user lands right in it.
 *
 * @var \App\Domain\Mitglied|null $mitglied
 * @var \App\App\Mitglied\Einbettung|null $einbettung
 * @var list<\App\App\Mitglied\Reiter> $reiter
 * @var string|null $gewaehlterReiter
 * @var string|null $reiterInhalt
 * @var list<string> $abschnitte
 * @var string|null $statusText
 * @var string|null $statusKlasse
 * @var string|null $fokus 'mitglied', 'reiter' or null
 */
?>
<div class="geteilt-detail">
    <?php if ($mitglied !== null && $einbettung !== null): ?>
        <?php $id = (int) $mitglied->id; ?>
        <?php if ($einbettung->zurueckText !== null): ?>
            <a class="zurueck zurueck-immer" <?= $einbettung->link($einbettung->rueckweg(), 'zurueck') ?>><?= e($einbettung->zurueckText) ?></a>
        <?php else: ?>
            <a class="zurueck" <?= $einbettung->link($einbettung->rueckweg()) ?>>Zurück zur Liste</a>
        <?php endif; ?>
        <div class="detail-kopf">
            <h2 id="mitglied-titel" tabindex="-1"<?= $fokus === 'mitglied' ? ' autofocus' : '' ?>><?= e($mitglied->anzeigeName()) ?></h2>
            <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span>
            <span class="etikett <?= e($statusKlasse) ?>"><?= e($statusText) ?></span>
            <?php if ($einbettung->zurueckText === null): ?>
                <a class="knopf knopf-still detail-schliessen" <?= $einbettung->link($einbettung->rueckweg()) ?>>Schließen</a>
            <?php endif; ?>
        </div>

        <?php foreach ($abschnitte as $abschnitt): ?>
            <?= $abschnitt ?>
        <?php endforeach; ?>

        <nav class="reiter" aria-label="Bereiche des Mitglieds">
            <ul>
                <?php foreach ($reiter as $eintrag): ?>
                    <?php $aktiv = $eintrag->schluessel() === $gewaehlterReiter; ?>
                    <li><a <?= $einbettung->link($einbettung->adresse($id, $eintrag->schluessel()), 'reiter') ?><?= $aktiv ? ' aria-current="page"' . ($fokus === 'reiter' ? ' autofocus' : '') : '' ?>><?= e($eintrag->titel()) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?= $reiterInhalt ?>
    <?php endif; ?>
</div>
