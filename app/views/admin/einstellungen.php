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
    <?php if (count($abschnitte) > 1): ?>
        <nav class="sprungleiste" aria-label="Abschnitte der Einstellungen">
            <ul>
                <?php foreach ($abschnitte as $abschnitt): ?>
                    <li><a href="#<?= e($abschnitt['schluessel']) ?>"><?= e($abschnitt['titel']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <?php foreach ($abschnitte as $abschnitt): ?>
        <section class="abschnitt" id="<?= e($abschnitt['schluessel']) ?>" aria-labelledby="titel-<?= e($abschnitt['schluessel']) ?>">
            <h2 id="titel-<?= e($abschnitt['schluessel']) ?>"><?= e($abschnitt['titel']) ?></h2>
            <?= $abschnitt['inhalt'] ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
