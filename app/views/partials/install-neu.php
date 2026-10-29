<?php

/**
 * "Neu beginnen": forgets this run of the installer and starts at the
 * database step again (App\Installer\InstallController::neu()).
 *
 * @var string $csrf
 */
?>
<form method="post" action="/install/neu" class="install-neu">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="knopf knopf-still">Neu beginnen</button>
</form>
