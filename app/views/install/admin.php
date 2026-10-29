<?php

/**
 * Step 4: the first admin - active at once. The second factor is set up at
 * the first login (docs/spec/02-sicherheit.md section 3).
 *
 * @var \App\Installer\InstallSchritt $schritt
 * @var string $csrf
 * @var array<string, string> $fehler
 * @var array{email: string, name: string} $admin
 */
$ueberschrift = 'Erster Admin-Zugang';
$min = \App\Service\Account\PasswordPolicy::MIN_LAENGE;
?>
<section class="install">
    <?php require __DIR__ . '/../partials/install-kopf.php'; ?>
    <p>Mit diesem Zugang melden Sie sich nach der Installation an und laden die übrigen Vorstandsmitglieder ein.
        Beim Absenden werden die Tabellen der Datenbank angelegt.</p>
    <form method="post" action="/install/admin" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="admin-name">Name</label>
            <input id="admin-name" name="name" type="text" value="<?= e($admin['name']) ?>" autocomplete="name" required>
        </div>
        <div class="feld">
            <label for="admin-email">E-Mail-Adresse</label>
            <input id="admin-email" name="email" type="email" value="<?= e($admin['email']) ?>" autocomplete="email" required>
        </div>
        <div class="feld">
            <label for="admin-passwort">Passwort</label>
            <input id="admin-passwort" name="passwort" type="password" autocomplete="new-password" minlength="<?= e($min) ?>" required aria-describedby="admin-passwort-hinweis">
            <p class="feld-hinweis" id="admin-passwort-hinweis">Mindestens <?= e($min) ?> Zeichen. Ein Satz aus mehreren Wörtern ist leicht zu merken und schwer zu erraten.</p>
        </div>
        <div class="feld">
            <label for="admin-wiederholung">Passwort wiederholen</label>
            <input id="admin-wiederholung" name="passwort_wiederholung" type="password" autocomplete="new-password" minlength="<?= e($min) ?>" required>
        </div>
        <div class="formular-aktionen">
            <button type="submit" class="knopf knopf-haupt">Admin anlegen</button>
        </div>
    </form>
    <?php require __DIR__ . '/../partials/install-neu.php'; ?>
</section>
