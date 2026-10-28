<?php

/**
 * The maintenance banner (docs/spec/04-installation-und-update.md section 4):
 * on every page behind the login, for whoever may release the flag. A
 * crashed update must not lock the instance for good - without this button
 * the only way back would be FTP.
 *
 * The release form posts to a fixed route that redirects to a fixed place.
 *
 * @var array{seit: string, grund: string}|null $wartung
 * @var \App\Domain\Konto|null $konto
 * @var string|null $csrfToken
 */

use App\Domain\Permission;

if (($wartung ?? null) !== null && ($konto ?? null) !== null && $konto->darf(Permission::AdminSystem)) :
    try {
        $seit = $wartung['seit'] === '' ? null : new \DateTimeImmutable($wartung['seit']);
    } catch (\Exception) {
        $seit = null;
    }
    ?>
    <div class="hinweis hinweis-warnung wartung-banner" role="status">
        <strong>Wartungsmodus ist aktiv</strong>
        <p>
            Grund: <?= e($wartung['grund']) ?><?= $seit !== null ? ', seit ' . e($seit->format('d.m.Y, H:i')) . ' Uhr' : '' ?>.
            Alle anderen sehen nur eine Wartungsseite (503); die Administration und die Anmeldung bleiben erreichbar.
            Läuft gerade ein Update, bitte warten, bis es fertig ist.
        </p>
        <form method="post" action="/admin/wartung/aufheben">
            <input type="hidden" name="_csrf" value="<?= e((string) $csrfToken) ?>">
            <button type="submit" class="knopf knopf-haupt">Wartungsmodus aufheben</button>
        </form>
    </div>
<?php endif; ?>
