<?php

/**
 * Settings section Runde Geburtstage (docs/spec/09-listen-und-exporte.md section 3).
 *
 * @var string $werte numbers separated by commas
 * @var string $csrf
 */
?>
<form method="post" action="/admin/geburtstage" class="formular formular-liste">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="feld">
        <label for="geburtstage-werte">Runde Geburtstage</label>
        <input id="geburtstage-werte" name="werte" type="text" value="<?= e($werte) ?>" aria-describedby="geburtstage-hinweis">
        <p id="geburtstage-hinweis" class="feld-hinweis">Alter, bei denen ein Geburtstag in der Geburtstagsliste hervorgehoben wird. Zum Beispiel 18, 30, 40, 50. Mit Komma oder Leerzeichen trennen.</p>
    </div>
    <button type="submit" class="knopf knopf-haupt">Runde Geburtstage speichern</button>
</form>
