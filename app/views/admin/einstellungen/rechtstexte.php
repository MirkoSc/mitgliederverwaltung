<?php

/**
 * The settings section "Rechtstexte": one form per legal text. The text is
 * plain Markdown (App\Support\Markdown); the public page shows it as entered.
 *
 * @var string $csrf
 * @var list<array{art: string, titel: string, pfad: string, text: string}> $felder
 * @var int $maxZeichen
 */
?>
<p>Impressum und Datenschutzerklärung schreibt der Verein selbst. Sie sind ohne Anmeldung unter
    <a href="/impressum">/impressum</a> und <a href="/datenschutz">/datenschutz</a> erreichbar.
    Einfaches Markdown: Überschrift mit <code># </code> oder <code>## </code>, Liste mit <code>- </code>,
    <code>**fett**</code>, Link als <code>[Text](https://…)</code>. Leerzeilen trennen Absätze.</p>

<?php foreach ($felder as $feld): ?>
    <form method="post" action="/admin/rechtstexte/<?= e($feld['art']) ?>" class="rechtstext-formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="feld">
            <label for="rechtstext-<?= e($feld['art']) ?>"><?= e($feld['titel']) ?></label>
            <textarea id="rechtstext-<?= e($feld['art']) ?>" name="text" rows="12" maxlength="<?= e($maxZeichen) ?>"
                      class="rechtstext-eingabe"><?= e($feld['text']) ?></textarea>
        </div>
        <p class="rechtstext-aktionen">
            <button type="submit" class="knopf knopf-haupt">Speichern</button>
            <a href="<?= e($feld['pfad']) ?>">Seite ansehen</a>
        </p>
    </form>
<?php endforeach; ?>
