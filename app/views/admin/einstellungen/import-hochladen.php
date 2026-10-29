<?php

/**
 * The upload form of the settings section Import - the same file for the
 * first import and for the reconciliation (docs/spec/10-import.md
 * sections 1 and 4); the server decides by the stock.
 *
 * @var string $knopfText
 * @var string $csrf
 */
?>
<form method="post" action="/admin/import/hochladen" enctype="multipart/form-data" class="formular">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="feld">
        <label for="import-datei">Datei import.csv</label>
        <input id="import-datei" name="datei" type="file" accept=".csv,text/csv" required aria-describedby="import-datei-hinweis">
        <p id="import-datei-hinweis" class="feld-hinweis">UTF-8, Trennzeichen „;“, eine Zeile je Mitglied.</p>
    </div>
    <button type="submit" class="knopf knopf-haupt"><?= e($knopfText) ?></button>
</form>
