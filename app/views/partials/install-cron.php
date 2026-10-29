<?php

/**
 * The cron URL for the hoster's control panel (docs/spec/01-plattform.md
 * section 5) - shown once at the end of the installation; the token is new
 * with every config.php. After a restore the script fills it in.
 *
 * @var string|null $cronUrl
 */
?>
<div class="install-cron">
    <h2>Cronjob einrichten</h2>
    <p>Legen Sie im Kundenmenü des Hosters einen Cronjob an, der etwa alle 10 Minuten diese Adresse aufruft.
        Er verschickt liegengebliebene Mails und räumt auf. Die Adresse enthält ein Geheimnis – nicht weitergeben.</p>
    <p class="install-cron-url"><code id="install-cron-url"><?= e($cronUrl ?? '') ?></code></p>
</div>
