<?php

/**
 * Settings section Update (docs/spec/04-installation-und-update.md section
 * 3): channel, search, the step chain, rollback. Only components of
 * /admin/designsystem; the chain runs in public/js/update.js - an external
 * script, never an inline one (CLAUDE.md section 4). The settings page
 * renders sections without a script list, so the section names its script
 * itself.
 *
 * @var string $installiert
 * @var string $kanal
 * @var \App\Service\Update\UpdateState|null $stand
 * @var list<string> $schritte
 * @var bool $zurueckMoeglich
 * @var string $version
 * @var string $csrf
 */

// A chain that was started and neither finished nor rolled back can go on
// after a reload - from the step after the last one that passed.
$unfertig = $stand !== null && $stand->zielVersion !== null && !$stand->fertig;
?>
<p>
    Installiert ist Version <strong><?= e($installiert) ?></strong>.
    Vor dem Umschalten legt das Update automatisch ein Backup an; während es läuft,
    ist die Seite für alle außer den Administratoren im Wartungsmodus.
</p>

<form method="post" action="/admin/update/kanal" class="formular">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="feld">
        <label for="update-kanal">Release-Kanal</label>
        <select id="update-kanal" name="kanal">
            <option value="stable"<?= $kanal === 'stable' ? ' selected' : '' ?>>stable – nur freigegebene Versionen</option>
            <option value="beta"<?= $kanal === 'beta' ? ' selected' : '' ?>>beta – auch Vorabversionen (Testinstanz)</option>
        </select>
    </div>
    <p class="knopfreihe">
        <button type="submit" class="knopf knopf-still">Kanal speichern</button>
    </p>
</form>

<div id="update-kette" data-csrf="<?= e($csrf) ?>" data-schritte="<?= e(implode(',', $schritte)) ?>"
     data-fortsetzen-nach="<?= e($unfertig ? ($stand->abgeschlossenerSchritt ?? '') : '') ?>"
     data-unfertig="<?= $unfertig ? '1' : '0' ?>">
    <noscript>
        <p class="hinweis hinweis-fehler">Für das Update wird JavaScript benötigt.</p>
    </noscript>
    <p class="knopfreihe">
        <button type="button" id="update-suchen" class="knopf knopf-haupt">Nach Updates suchen</button>
        <button type="button" id="update-starten" class="knopf knopf-haupt" hidden>Update starten</button>
        <?php if ($unfertig): ?>
            <button type="button" id="update-fortsetzen" class="knopf knopf-haupt">
                Update auf Version <?= e((string) $stand->zielVersion) ?> fortsetzen
            </button>
        <?php endif; ?>
    </p>

    <div id="update-verlauf" class="update-verlauf" hidden>
        <p id="update-status" aria-live="polite"></p>
        <ul id="update-log"></ul>
        <p id="update-fehler" class="hinweis hinweis-fehler" role="alert" hidden></p>
        <p id="update-aktionen" class="knopfreihe" hidden>
            <button type="button" id="update-wiederholen" class="knopf knopf-haupt">Schritt wiederholen</button>
            <button type="button" id="update-rollback-fehler" class="knopf knopf-gefahr" hidden>
                Auf das vorherige Release zurückgehen
            </button>
        </p>
        <p id="update-fertig" hidden>
            <button type="button" id="update-neuladen" class="knopf knopf-haupt">Seite neu laden</button>
        </p>
    </div>
</div>

<?php if ($stand !== null): ?>
    <h3>Letzter Stand</h3>
    <p>
        Version <?= e($stand->aktuelleVersion) ?><?= $stand->zielVersion !== null ? ' → ' . e($stand->zielVersion) : '' ?>,
        letzter Schritt: <?= e($stand->abgeschlossenerSchritt ?? '–') ?>,
        <?= $stand->fertig ? 'abgeschlossen' : 'offen' ?>.
    </p>
    <?php if ($stand->fehler !== null): ?>
        <p class="hinweis hinweis-fehler"><?= e($stand->fehler) ?></p>
    <?php endif; ?>
    <?php if ($stand->meldungen !== []): ?>
        <ul>
            <?php foreach ($stand->meldungen as $meldung): ?>
                <li><?= e($meldung) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="post" action="/admin/update/zuruecksetzen">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-still">Stand zurücksetzen</button>
        </p>
    </form>
<?php endif; ?>

<?php if ($zurueckMoeglich): ?>
    <h3>Rollback</h3>
    <p>
        Läuft nach einem Update etwas nicht, geht „Zurückgehen“ auf das vorherige Release.
        Die Daten bleiben, wie sie sind: Die Versionen sind so gebaut, dass das vorherige Release
        auch mit dem neuen Datenbankschema läuft. Ein früherer Datenstand lässt sich nur aus
        einem Backup einspielen.
    </p>
    <p class="knopfreihe">
        <button type="button" id="update-rollback" class="knopf knopf-gefahr">Auf das vorherige Release zurückgehen</button>
    </p>
<?php endif; ?>

<script src="/js/update.js?v=<?= e($version) ?>" defer></script>
