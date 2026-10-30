<?php

/**
 * The settings page: one section per topic, each found by
 * App\Admin\Einstellungen\Abschnitte and rendered from its own template.
 *
 * @var list<array{schluessel: string, titel: string, inhalt: string}> $abschnitte
 */
?>
<h1>Einstellungen</h1>
<?php if ($abschnitte === []): ?>
    <div class="platzhalter">
        <p>Hier stehen bald die Einstellungen des Vereins: Vereinsdaten und Bankverbindung, Abteilungen,
            Beitragsarten, Funktionen, runde Geburtstage, Ehrungsstufen, Benutzer, Import und System.</p>
    </div>
<?php else: ?>
    <?php /* Table of contents: built from the sections above, never kept by hand.
             public/js/inhaltsverzeichnis.js marks the current one. */ ?>
    <div class="mit-verzeichnis">
        <?php if (count($abschnitte) > 1): ?>
            <nav class="verzeichnis" aria-label="Abschnitte der Einstellungen" data-inhaltsverzeichnis>
                <ul>
                    <?php foreach ($abschnitte as $abschnitt): ?>
                        <li><a href="#<?= e($abschnitt['schluessel']) ?>"><?= e($abschnitt['titel']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
        <div class="verzeichnis-inhalt">
            <?php foreach ($abschnitte as $abschnitt): ?>
                <section class="abschnitt" id="<?= e($abschnitt['schluessel']) ?>" aria-labelledby="titel-<?= e($abschnitt['schluessel']) ?>">
                    <h2 id="titel-<?= e($abschnitt['schluessel']) ?>" tabindex="-1"><?= e($abschnitt['titel']) ?></h2>
                    <?= $abschnitt['inhalt'] ?>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
