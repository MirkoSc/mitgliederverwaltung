<?php

/**
 * Step 1: the database the hoster set up, and a connection test.
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $csrf
 * @var array<string, string> $fehler
 * @var array{host: string, port: int, name: string, user: string} $db
 * @var string $kanal
 */
$ueberschrift = 'Datenbank verbinden';
?>
<section class="install">
    <?php require __DIR__ . '/../partials/install-kopf.php'; ?>
    <p>Tragen Sie die Zugangsdaten der Datenbank ein, die Sie im Kundenmenü des Hosters angelegt haben.
        Die Daten werden erst am Ende der Installation gespeichert.</p>
    <form method="post" action="/install/datenbank" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="db-host">Server</label>
            <input id="db-host" name="db_host" type="text" value="<?= e($db['host']) ?>" autocomplete="off" spellcheck="false" required>
        </div>
        <div class="feld feld-kurz">
            <label for="db-port">Port</label>
            <input id="db-port" name="db_port" type="number" min="1" max="65535" value="<?= e($db['port']) ?>" required>
        </div>
        <div class="feld">
            <label for="db-name">Datenbankname</label>
            <input id="db-name" name="db_name" type="text" value="<?= e($db['name']) ?>" autocomplete="off" spellcheck="false" required>
        </div>
        <div class="feld">
            <label for="db-user">Benutzer</label>
            <input id="db-user" name="db_user" type="text" value="<?= e($db['user']) ?>" autocomplete="off" spellcheck="false" required>
        </div>
        <div class="feld">
            <label for="db-passwort">Passwort</label>
            <input id="db-passwort" name="db_password" type="password" autocomplete="off">
        </div>
        <div class="formular-aktionen">
            <button type="submit" class="knopf knopf-haupt">Verbindung prüfen</button>
        </div>
    </form>
    <p class="klein gedaempft">Update-Kanal aus setup.php: <strong><?= e($kanal) ?></strong>.</p>
</section>
