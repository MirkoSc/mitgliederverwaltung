<?php

/**
 * Settings section Ehrungsstufen (docs/spec/09-listen-und-exporte.md section 3).
 *
 * @var string $werte numbers separated by commas
 * @var string $csrf
 */
?>
<form method="post" action="/admin/ehrungsstufen" class="formular formular-liste">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="feld">
        <label for="ehrungsstufen-werte">Ehrungsstufen</label>
        <input id="ehrungsstufen-werte" name="werte" type="text" value="<?= e($werte) ?>" aria-describedby="ehrungsstufen-hinweis">
        <p id="ehrungsstufen-hinweis" class="feld-hinweis">Mitgliedsjahre, nach denen geehrt wird. Zum Beispiel 25, 40, 50, 60, 70. Mit Komma oder Leerzeichen trennen.</p>
    </div>
    <button type="submit" class="knopf knopf-haupt">Ehrungsstufen speichern</button>
</form>
