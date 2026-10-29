<?php

/**
 * Change the own password (user menu, docs/spec/06-oberflaeche.md
 * section 2). The current password is asked again; other devices are
 * logged out afterwards.
 *
 * @var string $csrf
 * @var int $minLaenge
 * @var list<string> $fehler
 */
?>
<section class="anmeldung">
    <h1>Passwort ändern</h1>
    <p>Nach der Änderung sind Sie auf allen anderen Geräten abgemeldet. Sie bekommen dazu eine Mail.</p>
    <?php require __DIR__ . '/../partials/fehlerliste.php'; ?>
    <form method="post" action="/app/konto/passwort" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="konto-aktuell">Aktuelles Passwort</label>
            <input id="konto-aktuell" name="aktuell" type="password" autocomplete="current-password" required>
        </div>
        <?php $idPraefix = 'konto'; require __DIR__ . '/../partials/passwort-felder.php'; ?>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Passwort ändern</button>
        </p>
    </form>
</section>
