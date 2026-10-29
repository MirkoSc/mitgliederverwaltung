<?php

/**
 * Settings section Mail: SMTP settings and the test mail.
 *
 * @var \App\Service\Mail\MailSettings $einstellungen
 * @var list<\App\Service\Mail\SmtpSecurity> $sicherheiten
 * @var int $fehlgeschlagen mails that gave up for good
 * @var string $csrf
 */
?>
<?php if ($fehlgeschlagen > 0): ?>
    <p class="hinweis hinweis-warnung">
        <?= e($fehlgeschlagen) ?> <?= $fehlgeschlagen === 1 ? 'Mail konnte' : 'Mails konnten' ?> nach mehreren Versuchen nicht versendet werden.
        Bitte die Einstellungen prüfen und eine Testmail senden.
    </p>
<?php elseif (!$einstellungen->istVollstaendig()): ?>
    <p class="hinweis hinweis-info">Der Mailversand ist noch nicht eingerichtet. Ohne ihn kommen keine Anmeldecodes und Einladungen an.</p>
<?php endif; ?>

<form method="post" action="/admin/mail" class="formular">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <div class="feld">
        <label for="mail-host">SMTP-Server</label>
        <input id="mail-host" name="host" type="text" value="<?= e($einstellungen->host) ?>" autocomplete="off" required>
    </div>
    <div class="feld">
        <label for="mail-port">Port</label>
        <input id="mail-port" name="port" type="number" min="1" max="65535" value="<?= e($einstellungen->port) ?>" required>
    </div>
    <div class="feld">
        <label for="mail-sicherheit">Verschlüsselung</label>
        <select id="mail-sicherheit" name="sicherheit">
            <?php foreach ($sicherheiten as $sicherheit): ?>
                <option value="<?= e($sicherheit->value) ?>"<?= $sicherheit === $einstellungen->sicherheit ? ' selected' : '' ?>><?= e($sicherheit->bezeichnung()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="feld">
        <label for="mail-benutzer">Benutzername</label>
        <input id="mail-benutzer" name="benutzer" type="text" value="<?= e($einstellungen->benutzer) ?>" autocomplete="off">
    </div>
    <div class="feld">
        <label for="mail-passwort">Passwort</label>
        <input id="mail-passwort" name="passwort" type="password" value="" autocomplete="new-password" aria-describedby="mail-passwort-hinweis">
        <p id="mail-passwort-hinweis" class="feld-hinweis">
            <?= $einstellungen->passwortGesetzt ? 'Ein Passwort ist gespeichert. Leer lassen, um es zu behalten.' : 'Noch kein Passwort gespeichert.' ?>
        </p>
        <?php if ($einstellungen->passwortGesetzt): ?>
            <label class="feld-haken"><input type="checkbox" name="passwort_entfernen" value="1"> Gespeichertes Passwort entfernen</label>
        <?php endif; ?>
    </div>
    <div class="feld">
        <label for="mail-absender">Absenderadresse</label>
        <input id="mail-absender" name="absender" type="email" value="<?= e($einstellungen->absender) ?>" required>
    </div>
    <div class="feld">
        <label for="mail-antwort">Antworten an (optional)</label>
        <input id="mail-antwort" name="antwort_an" type="email" value="<?= e($einstellungen->antwortAn) ?>">
    </div>
    <div class="feld">
        <label for="mail-vereinsname">Name im Absender (optional)</label>
        <input id="mail-vereinsname" name="vereinsname" type="text" value="<?= e($einstellungen->vereinsname) ?>">
    </div>
    <div class="feld">
        <label for="mail-url">Öffentliche Adresse dieser Anwendung (optional)</label>
        <input id="mail-url" name="oeffentliche_url" type="url" value="<?= e($einstellungen->oeffentlicheUrl) ?>" placeholder="https://mitglieder.beispiel.de" aria-describedby="mail-url-hinweis">
        <p id="mail-url-hinweis" class="feld-hinweis">Links in Mails zeigen auf diese Adresse. Ohne Angabe gibt es keine Links.</p>
    </div>

    <button type="submit" class="knopf knopf-haupt">Mail-Einstellungen speichern</button>
</form>

<form method="post" action="/admin/mail/test" class="formular formular-testmail">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <h3>Testmail</h3>
    <div class="feld">
        <label for="mail-test">Empfängeradresse</label>
        <input id="mail-test" name="empfaenger" type="email" required>
    </div>
    <button type="submit" class="knopf">Testmail senden</button>
</form>
