<?php

/**
 * The overview of the help (docs/spec/12-hilfe.md section 3): the guides
 * this role may carry out, and how to work with the keyboard alone - the
 * help is written for the Barrierefrei-Modus first.
 *
 * @var list<\App\Service\Hilfe\Anleitung> $anleitungen
 */

use App\App\HilfeController;

?>
<div class="seitenkopf">
    <h1>Hilfe</h1>
    <p>Hier steht Schritt für Schritt, wie Sie die häufigsten Aufgaben erledigen.</p>
</div>

<?php if ($anleitungen === []): ?>
    <div class="leer">
        <p>Es gibt noch keine Anleitungen.</p>
    </div>
<?php else: ?>
    <ul class="auswahl">
        <?php foreach ($anleitungen as $anleitung): ?>
            <li>
                <a href="<?= e(HilfeController::adresse($anleitung->slug)) ?>"><?= e($anleitung->titel) ?></a>
                <p><?= e($anleitung->kurz) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require __DIR__ . '/../partials/hilfe-tastatur.php'; ?>
