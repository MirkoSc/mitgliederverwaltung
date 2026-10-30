<?php

/**
 * The hints at the top of an opened member (App\App\Mitglied\
 * HinweiseAbschnitt). Nothing at all when there are none. They warn and
 * never block.
 *
 * With the address hint the payer's address stands beside it, and a button
 * copies it into the form of the tab "Daten" (public/js/anschrift.js). The
 * form is only on the page while that tab is open; on another tab the
 * button is a link to "Daten". Nothing is saved until the form is.
 *
 * @var list<\App\Service\Mitglied\Hinweis> $hinweise
 * @var \App\Domain\Mitglied|null $zahler the payer, only with the address hint
 * @var bool $darfBearbeiten
 * @var string|null $offenerReiter
 * @var int $mitgliedId
 * @var \App\App\Mitglied\Einbettung $einbettung the page the member is shown on
 */

use App\Service\Mitglied\HinweisArt;
?>
<?php if ($hinweise !== []): ?>
    <div class="hinweis hinweis-warnung" id="mitglied-hinweise">
        <strong><?= count($hinweise) === 1 ? 'Ein Hinweis zu diesem Mitglied' : e(count($hinweise)) . ' Hinweise zu diesem Mitglied' ?></strong>
        <ul>
            <?php foreach ($hinweise as $hinweis): ?>
                <li>
                    <?= e($hinweis->text) ?>
                    <?php if ($hinweis->art === HinweisArt::AndereAnschrift && $zahler !== null): ?>
                        <div class="zahler-anschrift">
                            <p>
                                Anschrift des Zahlers (<?= e($zahler->anzeigeName()) ?>):<br>
                                <?= e($zahler->strasse !== '' ? $zahler->strasse : '(keine Straße)') ?>,
                                <?= e(trim($zahler->plz . ' ' . $zahler->ort)) ?>
                            </p>
                            <?php if ($darfBearbeiten && $offenerReiter === 'daten'): ?>
                                <button type="button" class="knopf" data-anschrift-uebernehmen
                                        data-strasse="<?= e($zahler->strasse) ?>" data-plz="<?= e($zahler->plz) ?>"
                                        data-ort="<?= e($zahler->ort) ?>" data-land="<?= e($zahler->land) ?>">Anschrift des Zahlers übernehmen</button>
                                <p class="anschrift-stand" role="status"></p>
                            <?php elseif ($darfBearbeiten): ?>
                                <p><a <?= $einbettung->link($einbettung->adresse($mitgliedId, 'daten'), 'reiter') ?>>Zu den Daten, um die Anschrift zu übernehmen</a></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($hinweis->art->reiter() === 'daten' && $offenerReiter !== 'daten'): ?>
                        <p><a <?= $einbettung->link($einbettung->adresse($mitgliedId, 'daten'), 'reiter') ?>>Zu „Wohnort auf der Karte“ im Reiter „Daten“</a></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
