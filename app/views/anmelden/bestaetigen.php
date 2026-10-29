<?php

/**
 * Second step of the login (docs/spec/02-sicherheit.md section 3): the code
 * from the mail or the app, or a backup code - one field takes all three.
 *
 * @var string $csrf
 * @var \App\Domain\MfaMethode $methode
 * @var string|null $fehler
 * @var int $geraetTage
 */

use App\Domain\MfaMethode;

$perMail = $methode === MfaMethode::Email;
?>
<section class="anmeldung">
    <h1>Anmeldung bestätigen</h1>
    <?php if ($perMail): ?>
        <p>Wir haben Ihnen eine E-Mail mit einem 6-stelligen Code geschickt. Er gilt 10 Minuten.</p>
    <?php else: ?>
        <p>Öffnen Sie Ihre Authenticator-App und geben Sie den 6-stelligen Code für die Mitgliederverwaltung ein.</p>
    <?php endif; ?>
    <?php if (($fehler ?? null) !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert" id="bestaetigen-fehler"><?= e($fehler) ?></p>
    <?php endif; ?>

    <form method="post" action="/anmelden/bestaetigen" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="bestaetigen-code">Code</label>
            <input id="bestaetigen-code" name="code" type="text" class="feld-kurz code-eingabe"
                   inputmode="text" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false"
                   maxlength="20" required autofocus
                   aria-describedby="bestaetigen-code-hinweis<?= ($fehler ?? null) !== null ? ' bestaetigen-fehler' : '' ?>">
            <p class="feld-hinweis" id="bestaetigen-code-hinweis">
                Kein Zugriff auf <?= $perMail ? 'Ihre E-Mails' : 'die App' ?>? Geben Sie hier einen Ihrer Backup-Codes ein.
            </p>
        </div>
        <label class="feld-haken">
            <input type="checkbox" name="geraet_merken" value="1">
            Dieses Gerät <?= e((string) $geraetTage) ?> Tage merken
        </label>
        <p class="feld-hinweis">Nur auf einem eigenen Gerät, nicht auf einem geteilten Computer.</p>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Anmelden</button>
            <?php if ($perMail): ?>
                <button type="submit" class="knopf" formaction="/anmelden/bestaetigen/code-senden" formnovalidate>Code erneut senden</button>
            <?php endif; ?>
            <button type="submit" class="knopf knopf-still" formaction="/abmelden" formnovalidate>Abbrechen</button>
        </p>
    </form>
</section>
