<?php

/**
 * Choosing the second factor (docs/spec/02-sicherheit.md section 3): the
 * mail is the default and preselected; without mail settings only the app
 * is offered, and the page says why.
 *
 * @var string $csrf
 * @var bool $mailEingerichtet
 * @var string $email the own address, where the codes would go
 * @var string $knopf text of the submit button
 */
?>
<form method="post" action="/app/konto/zweiter-faktor/einrichten" class="formular">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <?php if (!$mailEingerichtet): ?>
        <p class="hinweis hinweis-info">
            Der Mailversand der Mitgliederverwaltung ist noch nicht eingerichtet. Deshalb geht im Moment nur
            die Authenticator-App. Sobald der Admin die Mail eingerichtet hat, können Sie hier auf
            „Code per E-Mail“ wechseln.
        </p>
    <?php endif; ?>
    <fieldset class="feldgruppe">
        <legend>Wie möchten Sie die Anmeldung bestätigen?</legend>
        <?php if ($mailEingerichtet): ?>
            <label class="feld-haken">
                <input type="radio" name="methode" value="email" checked aria-describedby="wahl-email-hinweis">
                Code per E-Mail
            </label>
            <p class="feld-hinweis" id="wahl-email-hinweis">Bei jeder Anmeldung kommt ein Code an <?= e($email) ?>. Keine App nötig.</p>
        <?php endif; ?>
        <label class="feld-haken">
            <input type="radio" name="methode" value="totp"<?= $mailEingerichtet ? '' : ' checked' ?> aria-describedby="wahl-app-hinweis">
            Authenticator-App
        </label>
        <p class="feld-hinweis" id="wahl-app-hinweis">Der Code kommt aus einer App auf Ihrem Handy und braucht keine Mail.</p>
    </fieldset>
    <p class="knopfreihe">
        <button type="submit" class="knopf knopf-haupt"><?= e($knopf) ?></button>
    </p>
</form>
