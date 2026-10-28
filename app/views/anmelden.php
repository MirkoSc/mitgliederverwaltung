<?php

/**
 * Placeholder of the login (issue #1); issue #6 replaces it with e-mail,
 * password and second factor.
 *
 * @var bool $entwicklung the development accounts are switched on
 * @var bool $abgelaufen the session ran out (App\Http\LoginGuard)
 * @var string $csrf
 */
?>
<section class="anmeldung">
    <h1>Anmelden</h1>
    <?php if ($abgelaufen): ?>
        <p class="hinweis hinweis-info">Ihre Sitzung ist abgelaufen. Bitte melden Sie sich neu an.</p>
    <?php endif; ?>

    <?php if ($entwicklung): ?>
        <p>Die Anmeldung mit E-Mail, Passwort und zweitem Faktor kommt noch. In dieser Entwicklungsumgebung
            sehen Sie die Seiten mit einem der beiden Beispielzugänge an.</p>
        <form method="post" action="/anmelden/entwicklung" class="anmeldung-wahl">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" name="konto" value="admin" class="knopf knopf-haupt">Als Admin ansehen</button>
            <button type="submit" name="konto" value="vorstand" class="knopf">Als Vorstand ansehen</button>
        </form>
    <?php else: ?>
        <p>Die Anmeldung ist noch nicht eingerichtet. Bitte versuchen Sie es nach dem nächsten Update erneut.</p>
    <?php endif; ?>
</section>
