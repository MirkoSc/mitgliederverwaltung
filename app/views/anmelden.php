<?php

/**
 * The login (docs/spec/02-sicherheit.md section 3): e-mail address and
 * password. One error sentence for every wrong combination - the page never
 * says whether the address exists.
 *
 * @var string $csrf
 * @var string $logoAnmeldungUrl club logo or placeholder (App\View\View)
 * @var string|null $weiter where to go after the login (already checked)
 * @var string|null $fehler
 * @var string|null $hinweis
 * @var string $email what the user typed, kept after a failed attempt
 */
?>
<section class="anmeldung">
    <img class="anmeldung-logo" src="<?= e($logoAnmeldungUrl) ?>" alt="" width="160" height="80">
    <h1>Anmelden</h1>
    <?php if (($hinweis ?? null) !== null): ?>
        <p class="hinweis hinweis-info"><?= e($hinweis) ?></p>
    <?php endif; ?>
    <?php if (($fehler ?? null) !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="anmelden-fehler"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="/anmelden" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php if (($weiter ?? null) !== null): ?>
            <input type="hidden" name="weiter" value="<?= e($weiter) ?>">
        <?php endif; ?>
        <div class="feld">
            <label for="anmelden-email">E-Mail-Adresse</label>
            <input id="anmelden-email" name="email" type="email" value="<?= e($email) ?>" autocomplete="username" required<?= ($fehler ?? null) !== null ? ' aria-describedby="anmelden-fehler"' : ' autofocus' ?>>
        </div>
        <div class="feld">
            <label for="anmelden-passwort">Passwort</label>
            <input id="anmelden-passwort" name="passwort" type="password" autocomplete="current-password" required<?= ($fehler ?? null) !== null ? ' autofocus' : '' ?>>
        </div>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Anmelden</button>
            <a href="/anmelden/passwort-vergessen">Passwort vergessen?</a>
        </p>
    </form>
</section>
