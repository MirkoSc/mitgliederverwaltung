<?php

/**
 * The ten backup codes, shown exactly once - as the answer to the POST
 * that made them; only their hashes are stored.
 *
 * @var list<string> $codes
 * @var string $meldung
 */
?>
<section class="anmeldung">
    <h1>Backup-Codes</h1>
    <p class="hinweis hinweis-ok"><?= e($meldung) ?></p>
    <p>
        Mit jedem dieser Codes können Sie sich einmal anmelden, wenn Sie gerade keinen Zugriff auf Ihre
        E-Mails oder Ihre App haben. Drucken oder notieren Sie sie jetzt und bewahren Sie sie sicher auf:
        Diese Seite zeigt sie nur dieses eine Mal.
    </p>
    <ul class="backup-codes">
        <?php foreach ($codes as $code): ?>
            <li><?= e($code) ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="knopfreihe">
        <button type="button" class="knopf" data-drucken>Drucken</button>
        <a class="knopf knopf-haupt" href="/app/konto/zweiter-faktor">Ich habe die Codes notiert</a>
    </p>
</section>
