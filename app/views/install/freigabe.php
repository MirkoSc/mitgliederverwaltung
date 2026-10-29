<?php

/**
 * Release of this browser session by the installation token
 * (App\Installer\InstallToken) - for whoever did not come straight from
 * setup.php.
 *
 * @var string $csrf
 * @var array<string, string> $fehler
 */
?>
<section class="install">
    <header class="install-kopf">
        <p class="install-marke">Mitgliederverwaltung einrichten</p>
        <h1>Installation freigeben</h1>
    </header>
    <?php $fehler = array_values($fehler); require __DIR__ . '/../partials/fehlerliste.php'; ?>
    <p>Die Installation ist geschützt, damit niemand anderes sie vor Ihnen abschließt. Normalerweise gibt
        <code>setup.php</code> die Freigabe beim Weiterleiten mit. Kommen Sie von woanders, tragen Sie das
        Installations-Token ein: Es steht in der Datei <code>shared/install_token.txt</code>, die Sie per FTP öffnen.</p>
    <form method="post" action="/install/freigabe" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="install-token">Installations-Token</label>
            <input id="install-token" name="token" type="text" autocomplete="off" spellcheck="false" required autofocus>
        </div>
        <div class="formular-aktionen">
            <button type="submit" class="knopf knopf-haupt">Installation freigeben</button>
        </div>
    </form>
</section>
