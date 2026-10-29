<?php

/**
 * Setting the second factor up, after the method was chosen: the code from
 * the mail, or the QR code and the first code from the app. Only the first
 * code makes the factor the account's (App\App\ZweiterFaktorController).
 *
 * @var string $csrf
 * @var \App\Domain\MfaMethode $methode
 * @var string $email
 * @var string|null $qrSvg server-made SVG (App\Support\QrCode), no user input in it
 * @var string|null $schluessel the secret as Base32 in groups of four
 * @var string|null $fehler
 */

use App\Domain\MfaMethode;

$perMail = $methode === MfaMethode::Email;
$fehler = $fehler === null ? [] : [$fehler];
?>
<section class="anmeldung">
    <h1>Zweiten Faktor einrichten</h1>
    <?php if ($perMail): ?>
        <p>Wir haben einen 6-stelligen Code an <?= e($email) ?> geschickt. Geben Sie ihn hier ein. Er gilt 10 Minuten.</p>
    <?php else: ?>
        <ol class="einrichtung-schritte">
            <li>Installieren Sie eine Authenticator-App auf Ihrem Handy, falls Sie noch keine haben.</li>
            <li>
                Fügen Sie in der App ein Konto hinzu und scannen Sie diesen QR-Code.
                <figure class="qr-code"><?= $qrSvg ?></figure>
                <p class="klein">
                    Scannen geht nicht? Geben Sie diesen Schlüssel in der App von Hand ein:<br>
                    <span class="schluessel"><?= e((string) $schluessel) ?></span>
                </p>
            </li>
            <li>Geben Sie den 6-stelligen Code ein, den die App jetzt zeigt.</li>
        </ol>
    <?php endif; ?>
    <?php require __DIR__ . '/../partials/fehlerliste.php'; ?>
    <form method="post" action="/app/konto/zweiter-faktor/abschliessen" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="einrichten-code">Code</label>
            <input id="einrichten-code" name="code" type="text" class="feld-kurz" inputmode="numeric"
                   autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="8" required autofocus>
        </div>
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-haupt">Einrichtung abschließen</button>
            <?php if ($perMail): ?>
                <button type="submit" class="knopf" formaction="/app/konto/zweiter-faktor/code-senden" formnovalidate>Code erneut senden</button>
            <?php endif; ?>
            <button type="submit" class="knopf knopf-still" formaction="/app/konto/zweiter-faktor/abbrechen" formnovalidate>Andere Methode wählen</button>
        </p>
    </form>
</section>
