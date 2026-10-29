<?php

/**
 * The own second factor (user menu, docs/spec/02-sicherheit.md section 3).
 * Not set up yet: choose a method - the only page such an account reaches.
 * Set up: what is in place; changing it needs password and code again.
 *
 * @var string $csrf
 * @var \App\Domain\MfaMethode|null $methode
 * @var bool $darfAendern
 * @var bool $mailEingerichtet
 * @var string $email
 * @var int $backupCodes unused backup codes
 * @var int $geraete remembered devices
 * @var string|null $fehler
 */

use App\Domain\MfaMethode;

$fehler = $fehler === null ? [] : [$fehler];
?>
<section class="anmeldung">
<?php if ($methode === null): ?>
    <h1>Zweiten Faktor einrichten</h1>
    <p>
        Zusätzlich zum Passwort fragt die Anmeldung künftig nach einem Code. So bleibt Ihr Konto geschützt,
        auch wenn jemand Ihr Passwort kennt. Bevor Sie weiterarbeiten können, richten Sie das bitte einmal ein.
    </p>
    <?php require __DIR__ . '/../partials/fehlerliste.php'; ?>
    <?php $knopf = 'Weiter'; require __DIR__ . '/../partials/faktor-wahl.php'; ?>
<?php else: ?>
    <h1>Zweiter Faktor</h1>
    <dl class="angaben">
        <dt>Bestätigung per</dt>
        <dd><?= e($methode->bezeichnung()) ?></dd>
        <dt>Backup-Codes</dt>
        <dd><?= e((string) $backupCodes) ?> von 10 übrig</dd>
        <dt>Gemerkte Geräte</dt>
        <dd><?= $geraete === 0 ? 'keine' : e((string) $geraete) ?></dd>
    </dl>
    <?php if ($backupCodes <= 3): ?>
        <p class="hinweis hinweis-warnung">Ihnen gehen die Backup-Codes aus. Erzeugen Sie unten neue.</p>
    <?php endif; ?>
    <?php if ($geraete > 0): ?>
        <form method="post" action="/app/konto/zweiter-faktor/geraete-vergessen" class="knopfreihe">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="knopf">Alle Geräte vergessen</button>
        </form>
    <?php endif; ?>

    <h2>Ändern</h2>
    <?php if (!$darfAendern): ?>
        <p>
            Wollen Sie die Methode wechseln, eine neue App einrichten oder neue Backup-Codes erzeugen?
            Bestätigen Sie dafür zuerst Ihr Passwort und einen aktuellen Code.
        </p>
        <?php require __DIR__ . '/../partials/fehlerliste.php'; ?>
        <form method="post" action="/app/konto/zweiter-faktor/bestaetigen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="feld">
                <label for="neu-passwort">Passwort</label>
                <input id="neu-passwort" name="passwort" type="password" autocomplete="current-password" required>
            </div>
            <div class="feld">
                <label for="neu-code">Code</label>
                <input id="neu-code" name="code" type="text" class="feld-kurz" inputmode="text"
                       autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="20" required
                       aria-describedby="neu-code-hinweis">
                <p class="feld-hinweis" id="neu-code-hinweis">
                    <?= $methode === MfaMethode::Email ? 'Den Code schicken wir Ihnen per E-Mail.' : 'Der Code aus Ihrer Authenticator-App.' ?>
                    Ein Backup-Code geht auch.
                </p>
            </div>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-haupt">Bestätigen</button>
                <?php if ($methode === MfaMethode::Email && $mailEingerichtet): ?>
                    <button type="submit" class="knopf" formaction="/app/konto/zweiter-faktor/code-senden" formnovalidate>Code per E-Mail senden</button>
                <?php endif; ?>
            </p>
        </form>
    <?php else: ?>
        <?php $knopf = 'Neu einrichten'; require __DIR__ . '/../partials/faktor-wahl.php'; ?>
        <form method="post" action="/app/konto/zweiter-faktor/backup-codes" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p>Neue Backup-Codes ersetzen alle bisherigen.</p>
            <p class="knopfreihe">
                <button type="submit" class="knopf">Neue Backup-Codes erzeugen</button>
            </p>
        </form>
    <?php endif; ?>
<?php endif; ?>
</section>
