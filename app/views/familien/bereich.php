<?php

/**
 * List of the families beside the opened family - or, in its place, a
 * person of it (the member building block, docs/spec/06-oberflaeche.md
 * section 5). The server marks an opened detail with geteilt-mit-detail;
 * on a phone and in the Barrierefrei-Modus that hides the list (one view
 * after the other).
 *
 * The search posts to the page itself, so a family name never travels in
 * a URL; htmx swaps only the result list while somebody types.
 *
 * @var string $text
 * @var list<\App\Domain\FamilienZeile> $zeilen
 * @var \App\App\Mitglied\Einbettung $liste
 * @var \App\Domain\Familie|null $familie
 * @var bool $familieUnbekannt
 * @var \App\App\Mitglied\GeoeffnetesMitglied $ansicht
 * @var bool $offen
 * @var string $detailHtml
 * @var string $formUrl
 * @var bool $darfPflegen
 * @var string|null $csrf
 */
?>
<div class="geteilt<?= $offen ? ' geteilt-mit-detail' : '' ?>" id="familien-bereich">
    <div class="geteilt-liste">
        <?php if ($familieUnbekannt || $ansicht->unbekannt): ?>
            <p class="hinweis hinweis-warnung"><?= $ansicht->unbekannt ? 'Dieses Mitglied gibt es nicht mehr. Möglicherweise wurde es inzwischen gelöscht.' : 'Diese Familie gibt es nicht mehr. Möglicherweise wurde sie inzwischen gelöscht oder hatte niemanden mehr.' ?></p>
        <?php endif; ?>

        <form class="suche" id="familien-form" method="post" action="<?= e($formUrl) ?>" role="search"
              hx-post="<?= e($formUrl) ?>" hx-target="#familien-ergebnis" hx-swap="innerHTML"
              hx-indicator="#familien-laedt" hx-sync="this:replace"
              hx-trigger="input changed delay:300ms from:#familien-text, submit">
            <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
            <div class="feld suche-feld">
                <label for="familien-text">Familie suchen</label>
                <input id="familien-text" name="text" type="search" value="<?= e($text) ?>"
                       placeholder="Name der Familie oder einer Person" autocomplete="off">
            </div>
            <div class="filter">
                <button type="submit" class="knopf">Suchen</button>
                <span class="laedt htmx-indicator" id="familien-laedt">Suche läuft …</span>
            </div>
        </form>

        <p class="nur-lesehilfe" id="familien-stand" role="status"><?= e(count($zeilen) === 1 ? '1 Familie gefunden' : count($zeilen) . ' Familien gefunden') ?></p>

        <div id="familien-ergebnis">
            <?php require __DIR__ . '/ergebnis.php'; ?>
        </div>

        <?php if ($darfPflegen): ?>
            <form method="post" action="<?= e(\App\App\FamilienController::PFAD . '/neu') ?>" class="formular knopfreihe familie-neu">
                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                <div class="feld">
                    <label for="familie-neu-name">Neue Familie</label>
                    <input type="text" id="familie-neu-name" name="name" maxlength="200" placeholder="z. B. Familie Muster" required>
                </div>
                <button type="submit" class="knopf">Familie anlegen</button>
            </form>
        <?php endif; ?>
    </div>

    <?= $detailHtml ?>
</div>
