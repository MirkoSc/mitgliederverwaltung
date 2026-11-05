<?php

/**
 * "Statistik – Beiträge" (docs/spec/09-listen-und-exporte.md section 7,
 * 08 section 2b, formerly "Verteilung der Beiträge"): what a year's fees
 * bring in - in total and by fee type, department, gender, age group,
 * place, aktiv/passiv, family and Zahlart - from the created fees, or
 * projected while there are none. Admin only; figures only, no names.
 *
 * @var \App\Service\Statistik\Beitragszahlen $zahlen
 * @var int|null $vorjahr
 * @var int|null $folgejahr
 * @var string $beitraegeAdresse
 * @var array<string, array{string, string}> $reiter
 * @var string $aktiverReiter
 */

use App\App\StatistikController;

$verteilung = $zahlen->verteilung;
$jahr = $verteilung->jahr;
?>
<?php require __DIR__ . '/reiter.php'; ?>

<nav class="blaettern" aria-label="Beitragsjahr">
    <?php if ($vorjahr !== null): ?>
        <a class="knopf" href="<?= e(StatistikController::beitragsAdresse($vorjahr)) ?>">Jahr <?= e($vorjahr) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <p class="blaettern-stand">Beitragsjahr <?= e($jahr) ?></p>
    <?php if ($folgejahr !== null): ?>
        <a class="knopf" href="<?= e(StatistikController::beitragsAdresse($folgejahr)) ?>">Jahr <?= e($folgejahr) ?></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
</nav>

<?php if ($verteilung->hochgerechnet): ?>
    <p class="statistik-stand">Für <?= e($jahr) ?> sind noch keine Jahresbeiträge erzeugt. Die Zahlen sind hochgerechnet
        aus den Beitragsarten der Mitglieder – so, wie „Beiträge für <?= e($jahr) ?> erzeugen“ sie heute anlegen würde.</p>
<?php else: ?>
    <p class="statistik-stand">Aus den erzeugten Jahresbeiträgen <?= e($jahr) ?> – offene, im Einzug und bezahlte
        zusammen. Mitglieder ohne erzeugten Beitrag, etwa Neueintritte, zählen erst nach erneutem Erzeugen mit.</p>
<?php endif; ?>

<?php if ($verteilung->anzahl === 0): ?>
    <div class="leer">
        <p>Für <?= e($jahr) ?> kommt nichts zusammen: Kein Mitglied hat eine Beitragsart mit einem Betrag über 0 €.</p>
        <p class="knopfreihe"><a class="knopf" href="<?= e($beitraegeAdresse) ?>">Zu den Beiträgen <?= e($jahr) ?></a></p>
    </div>
<?php else: ?>
    <dl class="kennzahlen">
        <div class="kennzahl">
            <dt><?= $verteilung->hochgerechnet ? 'Erwartete Summe' : 'Summe' ?></dt>
            <dd><?= e(geld($verteilung->summeCent)) ?></dd>
        </div>
        <div class="kennzahl">
            <dt>Beiträge</dt>
            <dd><?= e($verteilung->anzahl) ?></dd>
        </div>
    </dl>

    <p class="feld-hinweis statistik-erklaerung">Zählt, ob für das Jahr ein Beitrag besteht oder entstünde – wer zum
        1. Januar schon ausgetreten war, ist nicht dabei. Ein Familienbeitrag wird gleichmäßig auf den Zahler und die
        Familienmitglieder ohne eigenen Beitrag geteilt, bevor nach Abteilung, Geschlecht, Alter, Wohnort, aktiv/passiv
        oder Familie gezählt wird. Geteilt wird auf den Cent genau; jede Auswertung ergibt die Gesamtsumme. Merkmale
        wie Beitragsart und Wohnort sind die heutigen.</p>

    <nav class="sprungleiste" aria-label="Auswertungen">
        <ul>
            <?php foreach ($zahlen->auswertungen as $auswertung): ?>
                <li><a href="#<?= e($auswertung->id) ?>"><?= e($auswertung->titel) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <?php foreach ($zahlen->auswertungen as $auswertung): ?>
        <?php require __DIR__ . '/auswertung.php'; ?>
    <?php endforeach; ?>

    <p class="knopfreihe"><a class="knopf" href="<?= e($beitraegeAdresse) ?>">Zu den Beiträgen <?= e($jahr) ?></a></p>
<?php endif; ?>
