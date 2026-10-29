<?php

/**
 * Step 5: mail settings and a test mail - skippable. Without mail there is
 * no code by e-mail as second factor, only the app (02 section 3). The same
 * fields as the settings section Mail (app/views/admin/einstellungen/mail.php).
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $csrf
 * @var array<string, string> $fehler
 * @var \App\Service\Mail\MailSettings $mail
 * @var list<\App\Service\Mail\SmtpSecurity> $sicherheiten
 * @var string $empfaenger
 * @var array{ok: bool, text: string}|null $testergebnis
 */
$ueberschrift = 'Mailversand';
$testergebnis ??= null;
?>
<section class="install">
    <?php require __DIR__ . '/../partials/install-kopf.php'; ?>
    <?php if ($testergebnis !== null): ?>
        <p class="hinweis <?= $testergebnis['ok'] ? 'hinweis-ok' : 'hinweis-fehler' ?>" role="status"><?= e($testergebnis['text']) ?></p>
    <?php endif; ?>
    <p>Die Mitgliederverwaltung schickt Anmeldecodes, Einladungen und Passwort-Links per Mail.
        Die Zugangsdaten des Postfachs stehen im Kundenmenü des Hosters. Sie können den Schritt auch überspringen
        und den Mailversand später unter Einstellungen einrichten – bis dahin geht als zweiter Faktor nur eine Authenticator-App.</p>

    <form method="post" action="/install/mail" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="mail-host">SMTP-Server</label>
            <input id="mail-host" name="host" type="text" value="<?= e($mail->host) ?>" autocomplete="off" spellcheck="false">
        </div>
        <div class="feld feld-kurz">
            <label for="mail-port">Port</label>
            <input id="mail-port" name="port" type="number" min="1" max="65535" value="<?= e($mail->port) ?>">
        </div>
        <div class="feld">
            <label for="mail-sicherheit">Verschlüsselung</label>
            <select id="mail-sicherheit" name="sicherheit">
                <?php foreach ($sicherheiten as $sicherheit): ?>
                    <option value="<?= e($sicherheit->value) ?>"<?= $sicherheit === $mail->sicherheit ? ' selected' : '' ?>><?= e($sicherheit->bezeichnung()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="feld">
            <label for="mail-benutzer">Benutzername</label>
            <input id="mail-benutzer" name="benutzer" type="text" value="<?= e($mail->benutzer) ?>" autocomplete="off" spellcheck="false">
        </div>
        <div class="feld">
            <label for="mail-passwort">Passwort</label>
            <input id="mail-passwort" name="passwort" type="password" value="" autocomplete="new-password" aria-describedby="mail-passwort-hinweis">
            <p id="mail-passwort-hinweis" class="feld-hinweis"><?= $mail->passwortGesetzt ? 'Ein Passwort ist gespeichert. Leer lassen, um es zu behalten.' : 'Wird verschlüsselt gespeichert.' ?></p>
        </div>
        <div class="feld">
            <label for="mail-absender">Absenderadresse</label>
            <input id="mail-absender" name="absender" type="email" value="<?= e($mail->absender) ?>">
        </div>
        <div class="feld">
            <label for="mail-antwort">Antworten an (optional)</label>
            <input id="mail-antwort" name="antwort_an" type="email" value="<?= e($mail->antwortAn) ?>">
        </div>
        <div class="feld">
            <label for="mail-vereinsname">Name im Absender (optional)</label>
            <input id="mail-vereinsname" name="vereinsname" type="text" value="<?= e($mail->vereinsname) ?>">
        </div>
        <div class="feld">
            <label for="mail-url">Öffentliche Adresse dieser Anwendung (optional)</label>
            <input id="mail-url" name="oeffentliche_url" type="url" value="<?= e($mail->oeffentlicheUrl) ?>" placeholder="https://mitglieder.beispiel.de" aria-describedby="mail-url-hinweis">
            <p id="mail-url-hinweis" class="feld-hinweis">Links in Mails zeigen auf diese Adresse.</p>
        </div>
        <div class="feld">
            <label for="mail-test">Testmail an</label>
            <input id="mail-test" name="empfaenger" type="email" value="<?= e($empfaenger) ?>">
        </div>
        <div class="formular-aktionen">
            <button type="submit" name="aktion" value="testen" class="knopf">Testmail senden</button>
            <button type="submit" name="aktion" value="speichern" class="knopf knopf-haupt">Speichern und Installation abschließen</button>
        </div>
    </form>
    <form method="post" action="/install/mail" class="install-ueberspringen">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" name="aktion" value="ueberspringen" class="knopf knopf-still">Ohne Mailversand abschließen</button>
    </form>
</section>
