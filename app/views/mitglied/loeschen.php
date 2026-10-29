<?php

/**
 * The question before a member is deleted for good (admin only,
 * docs/spec/07-mitglieder.md section 1; 06 section 4 "Rückfragen"): in
 * whole sentences, what goes, what stays, and the gentler way for someone
 * who only left the club.
 *
 * @var \App\Domain\Mitglied $mitglied
 * @var bool $ehemalig
 * @var string|null $csrf
 */

use App\App\MitgliedController;

$id = (int) $mitglied->id;
?>
<div class="seitenkopf">
    <h1>Mitglied löschen?</h1>
    <p><?= e($mitglied->anzeigeName()) ?>, <span class="nummer">Nr. <?= e($mitglied->mitgliedsnummer) ?></span></p>
</div>

<div class="hinweis hinweis-warnung">
    <p>Das Mitglied wird endgültig gelöscht – mit allen Versionen im Verlauf, allen Nachweisen samt Dateien und
        allen Jahresbeiträgen. Das lässt sich nicht rückgängig machen.</p>
    <p>Bereits erzeugte Einzüge behalten ihre eigene Kopie der Angaben.</p>
</div>

<?php if (!$ehemalig): ?>
    <p>Wenn das Mitglied nur ausgetreten ist, tragen Sie besser ein Austrittsdatum ein: Dann bleibt es als
        ehemaliges Mitglied erhalten und verschwindet trotzdem aus den Listen.</p>
<?php endif; ?>

<form method="post" action="<?= e(MitgliedController::loeschAdresse($id)) ?>" class="knopfreihe">
    <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
    <button type="submit" class="knopf knopf-gefahr">Mitglied endgültig löschen</button>
    <a class="knopf knopf-still" href="<?= e(MitgliedController::adresse($id)) ?>">Nicht löschen, zurück zum Mitglied</a>
</form>
