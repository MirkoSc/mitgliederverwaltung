<?php

/**
 * "Passwort vergessen" (docs/spec/02-sicherheit.md section 3). After the
 * request the page says the same thing for every address - whether it has
 * an account stays unsaid.
 *
 * @var string $csrf
 * @var bool|null $gesendet
 * @var string|null $fehler
 */
?>
<section class="anmeldung">
    <h1>Passwort vergessen</h1>
    <?php if (($gesendet ?? false) === true): ?>
        <p class="hinweis hinweis-ok">Gehört die Adresse zu einem Konto, ist jetzt eine Mail mit einem Link unterwegs.
            Der Link ist 30 Minuten gültig.</p>
        <p>Keine Mail im Postfach? Sehen Sie im Spam-Ordner nach oder fragen Sie den Admin des Vereins.</p>
        <p><a href="/anmelden">Zur Anmeldung</a></p>
    <?php else: ?>
        <p>Geben Sie die E-Mail-Adresse Ihres Kontos ein. Sie bekommen einen Link, mit dem Sie ein neues Passwort festlegen.</p>
        <?php if (($fehler ?? null) !== null): ?>
            <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
        <?php endif; ?>
        <form method="post" action="/anmelden/passwort-vergessen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="feld">
                <label for="vergessen-email">E-Mail-Adresse</label>
                <input id="vergessen-email" name="email" type="email" autocomplete="username" required autofocus>
            </div>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-haupt">Link anfordern</button>
                <a href="/anmelden">Zurück zur Anmeldung</a>
            </p>
        </form>
    <?php endif; ?>
</section>
