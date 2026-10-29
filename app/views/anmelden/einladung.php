<?php

/**
 * The page behind the invitation link (docs/spec/02-sicherheit.md
 * section 5): the invited person sets the password; the account is active
 * afterwards. The token of the link waits in the session, not in the URL.
 *
 * @var string $csrf
 * @var int $minLaenge
 * @var list<string> $fehler
 */
?>
<section class="anmeldung">
    <h1>Einladung annehmen</h1>
    <p>Legen Sie Ihr Passwort fest. Danach melden Sie sich mit Ihrer E-Mail-Adresse und diesem Passwort an.</p>
    <?php require __DIR__ . '/../partials/fehlerliste.php'; ?>
    <form method="post" action="/anmelden/einladung" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php $idPraefix = 'einladung'; require __DIR__ . '/../partials/passwort-felder.php'; ?>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Passwort festlegen</button>
        </p>
    </form>
</section>
