<?php

/**
 * The page behind the reset link (docs/spec/02-sicherheit.md section 3).
 * The token of the link waits in the session (App\PublicPages\
 * AnmeldungController::tokenAusLink()), not in this page's URL.
 *
 * @var string $csrf
 * @var int $minLaenge
 * @var list<string> $fehler
 */
?>
<section class="anmeldung">
    <h1>Neues Passwort festlegen</h1>
    <p>Danach sind Sie auf allen Geräten abgemeldet und melden sich mit dem neuen Passwort an.</p>
    <?php require __DIR__ . '/../partials/fehlerliste.php'; ?>
    <form method="post" action="/anmelden/passwort-neu" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php $idPraefix = 'neu'; require __DIR__ . '/../partials/passwort-felder.php'; ?>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Passwort speichern</button>
        </p>
    </form>
</section>
